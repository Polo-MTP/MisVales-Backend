<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\Producto;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\RelacionPerdon;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

/**
 * Datos de prueba para GET /reportes/morosos (Módulo 9), que hasta ahora no tenía
 * ninguna Relacion en estado 'vencida'/'en_perdida' para mostrar. Construye a mano
 * (sin pasar por ValeService/RelacionCalculoService) pero replicando exactamente sus
 * fórmulas y transiciones para que los montos y el conteo de perdones sean consistentes
 * con lo que la app generaría en un flujo real.
 *
 * Idempotente a propósito (firstOrCreate/updateOrCreate por llaves naturales): puede
 * correrse varias veces sobre la misma base sin duplicar filas, y no falla contra
 * los índices únicos reales (referencia_pago, relacion_id en relacion_perdones, curp).
 */
final class MorosidadDemoSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidoraRole = Role::query()->where('name', 'Distribuidora')->first();
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();
        $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();
        $categoriaOro = CategoriaDistribuidora::query()->where('nombre', 'ORO')->first();
        $coordinador = User::query()->where('email', 'coordinador@correo.com')->first();
        $gerenteGeneral = User::query()->where('email', 'gerente.general@correo.com')->first();

        $productoA = Producto::query()->where('monto', 1500)->first();
        $productoB = Producto::query()->where('monto', 2000)->first();

        // --- Distribuidora A: un solo corte vencido, sin historial de perdones (caso simple) ---
        $distribuidoraA = $this->crearDistribuidora(
            email: 'moroso.norte@correo.com',
            nombre: 'Comercializadora del Norte SA de CV',
            numero: 'DIST-90001',
            rfc: 'CNO900101AB1',
            limiteCredito: 20000.00,
            categoriaId: $categoriaBronce?->id,
            distribuidoraRoleId: $distribuidoraRole?->id,
            sucursalId: $sucursalGomez?->id,
            coordinadorId: $coordinador?->id,
        );
        $clienteA = $this->crearCliente($distribuidoraA, 'Juana', 'Reyes', 'Ibarra', 'REIJ800101MDGYBN08');

        $valeA = $this->crearVale($distribuidoraA, $clienteA, $productoA, monto: 1500.00);

        $cuotaA = $this->calcularCuota(monto: 1500.00, quincenas: 10, recargo: 0.00);
        $relacionA = $this->crearRelacion(
            distribuidora: $distribuidoraA,
            categoriaIdSnapshot: $categoriaBronce?->id,
            porcentajeComisionSnapshot: $categoriaBronce?->porcentaje_comision,
            fechaCorte: now()->subMonths(2)->startOfMonth()->addDays(14),
            estado: 'vencida',
            totales: $cuotaA,
            generadaPor: $gerenteGeneral?->id,
        );
        $this->crearDetalle($relacionA, $valeA, $clienteA, $productoA, cuotaNumero: 1, cuotasTotales: 10, cuota: $cuotaA, estadoDetalle: 'vencido');

        // --- Distribuidora B: mismo vale arrastrado 3 cortes — 2 perdones ya usados y el 3er
        // atraso escala directo a 'en_perdida' (RelacionEstadoService::perdonar() con
        // limite_perdones_relacion=2 por defecto: a la 3a vez ya no perdona). ---
        $distribuidoraB = $this->crearDistribuidora(
            email: 'moroso.laguna@correo.com',
            nombre: 'Grupo Industrial La Laguna SA de CV',
            numero: 'DIST-90002',
            rfc: 'GIL900101CD2',
            limiteCredito: 30000.00,
            categoriaId: $categoriaOro?->id,
            distribuidoraRoleId: $distribuidoraRole?->id,
            sucursalId: $sucursalGomez?->id,
            coordinadorId: $coordinador?->id,
        );
        $clienteB = $this->crearCliente($distribuidoraB, 'Roberto', 'Aguilar', 'Nevárez', 'AUNR780512HDGGVB03');

        $valeB = $this->crearVale($distribuidoraB, $clienteB, $productoB, monto: 2000.00);

        // Cuota 1: primer atraso, sin recargo previo (no hay cuota anterior).
        $cuotaB1 = $this->calcularCuota(monto: 2000.00, quincenas: 10, recargo: 0.00);
        $relacionB1 = $this->crearRelacion(
            distribuidora: $distribuidoraB,
            categoriaIdSnapshot: $categoriaOro?->id,
            porcentajeComisionSnapshot: $categoriaOro?->porcentaje_comision,
            fechaCorte: now()->subMonths(3)->startOfMonth()->addDays(14),
            estado: 'perdonada',
            totales: $cuotaB1,
            generadaPor: $gerenteGeneral?->id,
        );
        $this->crearDetalle($relacionB1, $valeB, $clienteB, $productoB, cuotaNumero: 1, cuotasTotales: 10, cuota: $cuotaB1, estadoDetalle: 'vencido');
        $this->crearPerdon($distribuidoraB, $relacionB1, numeroPerdon: 1, autorizadoPor: $gerenteGeneral?->id, motivo: 'Cliente en gestión de cobranza, primera prórroga otorgada.');

        // Cuota 2: cuota anterior seguía 'vencido' → recargo por multa_no_pago (300).
        $cuotaB2 = $this->calcularCuota(monto: 2000.00, quincenas: 10, recargo: 300.00);
        $relacionB2 = $this->crearRelacion(
            distribuidora: $distribuidoraB,
            categoriaIdSnapshot: $categoriaOro?->id,
            porcentajeComisionSnapshot: $categoriaOro?->porcentaje_comision,
            fechaCorte: now()->subMonths(2)->startOfMonth()->addDays(14),
            estado: 'perdonada',
            totales: $cuotaB2,
            generadaPor: $gerenteGeneral?->id,
        );
        $this->crearDetalle($relacionB2, $valeB, $clienteB, $productoB, cuotaNumero: 2, cuotasTotales: 10, cuota: $cuotaB2, estadoDetalle: 'vencido');
        $this->crearPerdon($distribuidoraB, $relacionB2, numeroPerdon: 2, autorizadoPor: $gerenteGeneral?->id, motivo: 'Segunda prórroga, límite de perdones alcanzado.');

        // Cuota 3: límite de perdones ya alcanzado (2) → escala directo a 'en_perdida', sin RelacionPerdon.
        $cuotaB3 = $this->calcularCuota(monto: 2000.00, quincenas: 10, recargo: 300.00);
        $relacionB3 = $this->crearRelacion(
            distribuidora: $distribuidoraB,
            categoriaIdSnapshot: $categoriaOro?->id,
            porcentajeComisionSnapshot: $categoriaOro?->porcentaje_comision,
            fechaCorte: now()->subMonths(1)->startOfMonth()->addDays(14),
            estado: 'en_perdida',
            totales: $cuotaB3,
            generadaPor: $gerenteGeneral?->id,
        );
        $this->crearDetalle($relacionB3, $valeB, $clienteB, $productoB, cuotaNumero: 3, cuotasTotales: 10, cuota: $cuotaB3, estadoDetalle: 'vencido');
    }

    private function crearDistribuidora(
        string $email,
        string $nombre,
        string $numero,
        string $rfc,
        float $limiteCredito,
        ?int $categoriaId,
        ?int $distribuidoraRoleId,
        ?int $sucursalId,
        ?int $coordinadorId,
    ): Distribuidora {
        $usuario = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $nombre,
                'password' => Hash::make('Password123!'),
                'role_id' => $distribuidoraRoleId,
                'sucursal_id' => $sucursalId,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        return Distribuidora::query()->updateOrCreate(
            ['numero_distribuidora' => $numero],
            [
                'usuario_id' => $usuario->id,
                'limite_credito' => $limiteCredito,
                'puntos_acumulados' => 0,
                'estado' => 'MOROSO',
                'sucursal_id' => $sucursalId,
                'coordinador_id' => $coordinadorId,
                'razon_social' => $nombre,
                'rfc' => $rfc,
                'categoria_id' => $categoriaId,
                'comentarios_verificador' => 'Verificado correctamente',
                'fecha_aprobacion' => now()->subMonths(4),
                'aprobado_por' => $coordinadorId,
            ]
        );
    }

    private function crearCliente(Distribuidora $distribuidora, string $nombre, string $apellidoPaterno, string $apellidoMaterno, string $curp): Cliente
    {
        $datosPersonales = DatosPersonales::query()->where('curp', $curp)->first();

        if (! $datosPersonales) {
            $direccion = Direccion::create([
                'calle' => 'Av. Revolución 123',
                'colonia' => 'Centro',
                'numero_ext' => '123',
                'numero_int' => null,
                'codigo_postal' => '35000',
                'estado' => 'Durango',
                'ciudad' => 'Gómez Palacio',
            ]);

            $datosPersonales = DatosPersonales::create([
                'nombre' => $nombre,
                'apellido_paterno' => $apellidoPaterno,
                'apellido_materno' => $apellidoMaterno,
                'curp' => $curp,
                'direccion_id' => $direccion->id,
                'fecha_nacimiento' => '1985-01-01',
                'lugar_nacimiento' => 'Durango',
            ]);
        }

        $cliente = Cliente::query()->firstOrCreate(
            ['datos_id' => $datosPersonales->id],
            ['estado' => true]
        );

        HistorialClienteDistr::query()->firstOrCreate(
            [
                'distribuidor_id' => $distribuidora->id,
                'cliente_id' => $cliente->id,
                'fecha_fin' => null,
            ],
            ['fecha_inicio' => now()->subMonths(4)]
        );

        return $cliente;
    }

    private function crearVale(Distribuidora $distribuidora, Cliente $cliente, ?Producto $producto, float $monto): Vale
    {
        return Vale::query()->firstOrCreate(
            [
                'distribuidora_id' => $distribuidora->id,
                'cliente_id' => $cliente->id,
                'monto' => $monto,
            ],
            [
                'producto_id' => $producto?->id,
                'quincenas' => 10,
                'tipo' => 'vale-digital',
                'estado' => 'vencido',
                'fecha_solicitud' => now()->subMonths(3),
                'fecha_autorizacion' => now()->subMonths(3)->addDay(),
            ]
        );
    }

    /**
     * @return array{capital: float, comision: float, interes: float, seguro: float, recargo: float, total: float}
     */
    private function calcularCuota(float $monto, int $quincenas, float $recargo): array
    {
        $comisionBasePct = 10.0;
        $interesPctQuincena = 5.0;
        $seguro = 50.00;

        $capital = round($monto / $quincenas, 2);
        $comision = round(($monto * $comisionBasePct / 100) / $quincenas, 2);
        $interes = round($monto * $interesPctQuincena / 100, 2);
        $total = round($capital + $comision + $interes + $seguro + $recargo, 2);

        return compact('capital', 'comision', 'interes', 'seguro', 'recargo', 'total');
    }

    /**
     * @param array{capital: float, comision: float, interes: float, seguro: float, recargo: float, total: float} $totales
     */
    private function crearRelacion(
        Distribuidora $distribuidora,
        ?int $categoriaIdSnapshot,
        string|float|null $porcentajeComisionSnapshot,
        Carbon $fechaCorte,
        string $estado,
        array $totales,
        ?int $generadaPor,
    ): Relacion {
        $fechaLimitePago = $fechaCorte->copy()->addDay();
        $referenciaPago = sprintf('%09d%09d', $distribuidora->id, (int) $fechaCorte->format('Ymd'));

        return Relacion::query()->firstOrCreate(
            ['referencia_pago' => $referenciaPago],
            [
                'distribuidora_id' => $distribuidora->id,
                'sucursal_id' => $distribuidora->sucursal_id,
                'fecha_corte' => $fechaCorte,
                'fecha_limite_pago' => $fechaLimitePago,
                'fecha_pago_anticipado_desde' => $fechaLimitePago->copy()->subDays(3),
                'fecha_pago_anticipado_hasta' => $fechaLimitePago->copy()->subDay(),
                'limite_credito_snapshot' => $distribuidora->limite_credito,
                'categoria_id_snapshot' => $categoriaIdSnapshot,
                'porcentaje_comision_snapshot' => $porcentajeComisionSnapshot,
                'total_capital' => $totales['capital'],
                'total_comision' => $totales['comision'],
                'total_interes' => $totales['interes'],
                'total_seguro' => $totales['seguro'],
                'total_recargos' => $totales['recargo'],
                'total_a_pagar' => $totales['total'],
                'total_abonado' => 0,
                'estado' => $estado,
                'generada_por' => $generadaPor,
            ]
        );
    }

    /**
     * @param array{capital: float, comision: float, interes: float, seguro: float, recargo: float, total: float} $cuota
     */
    private function crearDetalle(Relacion $relacion, Vale $vale, Cliente $cliente, ?Producto $producto, int $cuotaNumero, int $cuotasTotales, array $cuota, string $estadoDetalle): RelacionDetalle
    {
        return RelacionDetalle::query()->firstOrCreate(
            [
                'relacion_id' => $relacion->id,
                'cuota_numero' => $cuotaNumero,
            ],
            [
                'vale_id' => $vale->id,
                'cliente_id' => $cliente->id,
                'producto_id' => $producto?->id,
                'cuotas_totales' => $cuotasTotales,
                'capital' => $cuota['capital'],
                'comision' => $cuota['comision'],
                'interes' => $cuota['interes'],
                'seguro' => $cuota['seguro'],
                'recargo' => $cuota['recargo'],
                'pago' => 0,
                'total' => $cuota['total'],
                'estado' => $estadoDetalle,
            ]
        );
    }

    private function crearPerdon(Distribuidora $distribuidora, Relacion $relacion, int $numeroPerdon, ?int $autorizadoPor, string $motivo): RelacionPerdon
    {
        return RelacionPerdon::query()->firstOrCreate(
            ['relacion_id' => $relacion->id],
            [
                'distribuidora_id' => $distribuidora->id,
                'numero_perdon' => $numeroPerdon,
                'autorizado_por' => $autorizadoPor,
                'motivo' => $motivo,
            ]
        );
    }
}
