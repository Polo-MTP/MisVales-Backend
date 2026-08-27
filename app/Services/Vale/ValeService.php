<?php

declare(strict_types=1);

namespace App\Services\Vale;

use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\Producto;
use App\Models\User;
use App\Models\Vale;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ValeService
{
    /**
     * Registra la solicitud de un vale: la Distribuidora elige un cliente propio y un
     * producto del catálogo activo. Queda en estado 'solicitado' hasta que alguien con
     * autoridad (Coordinador/Gerente) lo autorice — solo entonces cuenta contra el crédito
     * disponible y entra en el cálculo de cortes (RelacionCalculoService).
     *
     * Un cliente solo puede tener un vale sin liquidar a la vez (cualquier estado que no
     * sea 'pagado'); debe pagarse el vigente antes de poder solicitarle uno nuevo.
     *
     * @param  array<string, mixed>  $data
     */
    public function solicitar(array $data, User $usuario): Vale
    {
        $distribuidora = $usuario->distribuidora;

        if (! $distribuidora) {
            abort(403, 'Este usuario no tiene una distribuidora asociada.');
        }

        /** @var Cliente $cliente */
        $cliente = Cliente::query()->findOrFail($data['cliente_id']);

        $perteneceADistribuidora = $cliente->historialDistribuidoras()
            ->where('distribuidor_id', $distribuidora->id)
            ->whereNull('fecha_fin')
            ->exists();

        if (! $perteneceADistribuidora) {
            abort(403, 'Este cliente no está asignado a tu distribuidora.');
        }

        // Un cliente solo puede tener un vale activo/pendiente a la vez (cualquier estado
        // que no sea 'pagado', y que no haya sido desactivado por la propia distribuidora).
        $tieneValeSinLiquidar = Vale::query()
            ->where('cliente_id', $cliente->id)
            ->where('activo', true)
            ->where('estado', '!=', 'pagado')
            ->exists();

        if ($tieneValeSinLiquidar) {
            throw new DomainException('Este cliente ya tiene un vale activo o pendiente. Debe liquidarse antes de poder solicitar otro.');
        }

        /** @var Producto $producto */
        $producto = Producto::query()->where('activo', true)->findOrFail($data['producto_id']);

        // "Primer vale" = nunca tuvo uno que de verdad llegara a autorizarse -- uno en
        // 'solicitado'/'validado' no cuenta contra el crédito (ver
        // Distribuidora::getCreditoDisponibleAttribute()) y se puede desactivar/reactivar
        // libremente, así que contarlo aquí dejaba "estrenar" la caución del 50% con un vale de
        // juguete: se pedía uno chiquito sin autorizar y el siguiente, grande, ya no quedaba
        // limitado por la regla del primer vale.
        $esPrimerVale = ! $distribuidora->vales()->whereNotIn('estado', ['solicitado', 'validado'])->exists();

        if (! $distribuidora->puedeSolicitarVale((float) $producto->monto, $esPrimerVale)) {
            throw new DomainException('La distribuidora no cumple las condiciones para solicitar este vale (crédito disponible insuficiente o límite del primer vale excedido).');
        }

        return DB::transaction(function () use ($distribuidora, $cliente, $producto, $data): Vale {
            /** @var Vale $vale */
            $vale = Vale::query()->create([
                'distribuidora_id' => $distribuidora->id,
                'cliente_id' => $cliente->id,
                'producto_id' => $producto->id,
                'monto' => $producto->monto,
                'quincenas' => $producto->quincenas,
                'tipo' => $data['tipo'] ?? 'pre-vale',
                'estado' => 'solicitado',
                'fecha_solicitud' => now(),
            ]);

            return $vale->fresh(['distribuidora', 'cliente.datosPersonales.direccion', 'producto']);
        });
    }

    /**
     * La cajera valida en persona los datos del cliente contra el vale solicitado — paso
     * obligatorio antes de poder autorizarlo/pagarlo, no se puede saltar directo a autorizar.
     * Debe confirmar explícitamente que revisó la INE y el comprobante de domicilio del
     * cliente (no se puede validar si alguno de los dos falta o no coincide).
     *
     * El pago del vale se transfiere al cliente, así que la primera vez que se valida un vale
     * suyo se le pide su CLABE interbancaria; queda guardada (cifrada) en el cliente para los
     * vales futuros, no hace falta volver a capturarla cada vez.
     */
    public function validar(
        Vale $vale,
        User $usuario,
        ?string $clabe = null,
        ?bool $ineVerificada = null,
        ?bool $comprobanteDomicilioVerificado = null,
    ): Vale {
        if ($vale->estado !== 'solicitado') {
            throw new DomainException("Solo se pueden validar vales en estado 'solicitado' (actual: {$vale->estado}).");
        }

        // La distribuidora pudo haber "cancelado" este vale (desactivar(), que solo aplica
        // mientras sigue en 'solicitado') sin que su estado cambie de 'solicitado' -- sin este
        // chequeo, la cajera podía seguir validándolo y autorizándolo igual, comprometiendo
        // crédito real de una solicitud que la distribuidora creía cancelada.
        if (! $vale->activo) {
            throw new DomainException('Este vale fue desactivado por la distribuidora y ya no puede validarse.');
        }

        if ($ineVerificada === false || $comprobanteDomicilioVerificado === false) {
            throw new DomainException('No se puede validar el vale: la INE y el comprobante de domicilio del cliente deben coincidir. Corrige los datos o pide autorización para editarlos antes de continuar.');
        }

        /** @var Cliente $cliente */
        $cliente = $vale->cliente;

        if (! $cliente->clabe) {
            if (! $clabe) {
                throw new DomainException('Este cliente no tiene CLABE interbancaria registrada. Captúrala para poder validar y transferirle el pago.');
            }

            $cliente->clabe = $clabe;
            $cliente->save();
        }

        $vale->estado = 'validado';
        $vale->validado_por = $usuario->id;
        $vale->fecha_validacion = now();
        $vale->ine_verificada = $ineVerificada;
        $vale->comprobante_domicilio_verificado = $comprobanteDomicilioVerificado;
        $vale->save();

        return $vale->fresh(['distribuidora', 'cliente.datosPersonales.direccion', 'producto']);
    }

    /**
     * Autoriza (paga) un vale ya validado: a partir de aquí cuenta contra el crédito
     * disponible de la distribuidora y queda elegible para el corte (RelacionCalculoService).
     * No se puede autorizar/pagar sin haber validado los datos del cliente primero.
     *
     * El crédito se revisa aquí, no en solicitar(): un vale 'solicitado' todavía no cuenta
     * contra el crédito disponible, así que nada impide solicitar de más mientras no se
     * autorice. lockForUpdate() sobre la distribuidora evita que dos autorizaciones
     * concurrentes (de vales distintos) lean el mismo crédito disponible "antes" y ambas
     * pasen, dejando a la distribuidora por encima de su límite.
     */
    public function autorizar(Vale $vale, User $usuario): Vale
    {
        if ($vale->estado !== 'validado') {
            throw new DomainException("Solo se pueden autorizar vales ya validados (actual: {$vale->estado}). Valida los datos del cliente primero.");
        }

        if (! $vale->activo) {
            throw new DomainException('Este vale fue desactivado por la distribuidora y ya no puede autorizarse.');
        }

        return DB::transaction(function () use ($vale): Vale {
            /** @var Distribuidora $distribuidora */
            $distribuidora = Distribuidora::query()->whereKey($vale->distribuidora_id)->lockForUpdate()->firstOrFail();

            // La caución del primer vale (regla_50_pct) solo se revisaba al SOLICITAR, no aquí.
            // Una distribuidora sin historial podía solicitar varios vales en paralelo, cada uno
            // por debajo del tope individual (todos "primer vale" porque ninguno había llegado a
            // autorizarse todavía), y autorizarlos uno por uno sin que esta caución volviera a
            // aplicar -- terminando con el 100% de su crédito comprometido en su primer ciclo.
            // Se vuelve a evaluar "primer vale" aquí, en el momento real en que el crédito se
            // compromete, excluyendo el vale que se está autorizando ahora mismo.
            $esPrimerVale = ! Vale::query()
                ->where('distribuidora_id', $distribuidora->id)
                ->whereKeyNot($vale->id)
                ->whereNotIn('estado', ['solicitado', 'validado'])
                ->exists();

            if ((float) $vale->monto > $distribuidora->montoMaximoDisponible($esPrimerVale)) {
                throw new DomainException('La distribuidora ya no tiene crédito disponible suficiente para autorizar este vale.');
            }

            $vale->estado = 'autorizado';
            $vale->fecha_autorizacion = now();
            $vale->save();

            return $vale->fresh(['distribuidora', 'cliente.datosPersonales.direccion', 'producto']);
        });
    }

    /**
     * La distribuidora puede desactivar/activar sus propios vales libremente, sin autorización
     * de nadie más, pero solo mientras el vale sigue en 'solicitado' (aún no cuenta contra el
     * crédito ni entra a un corte). Una vez autorizado, pagado, vencido o parcial, el vale ya
     * está comprometido: desactivarlo no debe poder "liberar" ese crédito artificialmente.
     */
    public function desactivar(Vale $vale, User $usuario): Vale
    {
        $this->verificarPropiedad($vale, $usuario);

        if ($vale->estado !== 'solicitado') {
            throw new DomainException("Solo se pueden desactivar vales en estado 'solicitado' (actual: {$vale->estado}). Un vale autorizado, pagado, vencido o parcial ya cuenta contra el crédito y no puede desactivarse desde aquí.");
        }

        $vale->update(['activo' => false]);

        return $vale->fresh(['distribuidora', 'cliente.datosPersonales.direccion', 'producto']);
    }

    /**
     * Reactiva un vale que la propia distribuidora había desactivado -- misma regla que
     * desactivar(): solo aplica mientras siga en 'solicitado'.
     */
    public function activar(Vale $vale, User $usuario): Vale
    {
        $this->verificarPropiedad($vale, $usuario);

        if ($vale->estado !== 'solicitado') {
            throw new DomainException("Solo se pueden reactivar vales en estado 'solicitado' (actual: {$vale->estado}).");
        }

        $vale->update(['activo' => true]);

        return $vale->fresh(['distribuidora', 'cliente.datosPersonales.direccion', 'producto']);
    }

    /**
     * Lista vales. Para el rol Distribuidora, solo los suyos; para Cajera y Gerente de Sucursal,
     * solo los de distribuidoras de su propia sucursal; para Coordinador/Gerente General, todos
     * (opcionalmente filtrados por distribuidora_id/estado).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = Vale::query()->with(['distribuidora', 'cliente.datosPersonales.direccion', 'producto', 'relacionDetalles.relacion']);

        $role = $usuario->role?->name;

        if ($role === 'Distribuidora') {
            $distribuidora = $usuario->distribuidora;
            $query->where('distribuidora_id', $distribuidora?->id ?? 0);
        } elseif (in_array($role, ['Cajera', 'Gerente de Sucursal'], true)) {
            $sucursalId = $usuario->sucursal_id ?? 0;
            $query->whereHas('distribuidora', fn ($q) => $q->where('sucursal_id', $sucursalId));

            if (! empty($filters['distribuidora_id'])) {
                $query->where('distribuidora_id', (int) $filters['distribuidora_id']);
            }
        } elseif (! empty($filters['distribuidora_id'])) {
            $query->where('distribuidora_id', (int) $filters['distribuidora_id']);
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function verificarPropiedad(Vale $vale, User $usuario): void
    {
        if ($vale->distribuidora_id !== $usuario->distribuidora?->id) {
            abort(403, 'Este vale no pertenece a tu distribuidora.');
        }
    }
}
