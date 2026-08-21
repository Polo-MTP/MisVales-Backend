<?php

declare(strict_types=1);

namespace App\Services\Usuario;

use App\Models\AbonoConciliacion;
use App\Models\HistorialEstadoDistribuidora;
use App\Models\Relacion;
use App\Models\RelacionPerdon;
use App\Models\SolicitudAumentoCredito;
use App\Models\SolicitudConciliacion;
use App\Models\SolicitudEdicionCliente;
use App\Models\SolicitudProveedor;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Junta, en un solo feed cronológico, cualquier decisión donde el usuario aparezca como
 * quien autorizó/decidió/cambió algo — sin importar la tabla de origen. No incluye
 * "solicitado_por" (eso es quien PIDIÓ, no quien decidió) ni acciones de otros roles sobre
 * la misma solicitud (ej. verificador_id/coordinador_id en SolicitudProveedor).
 */
final class MovimientosAutorizadosService
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listar(User $usuario, ?string $desde = null, ?string $hasta = null, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $movimientos = $this->recolectar($usuario)
            ->when($desde, fn (Collection $c) => $c->filter(fn (array $m) => $m['fecha'] && $m['fecha']->gte(Carbon::parse($desde)->startOfDay())))
            ->when($hasta, fn (Collection $c) => $c->filter(fn (array $m) => $m['fecha'] && $m['fecha']->lte(Carbon::parse($hasta)->endOfDay())))
            ->sortByDesc(fn (array $m) => $m['fecha'])
            ->values();

        $total = $movimientos->count();
        $pagina = $movimientos->forPage($page, $perPage)->values();

        return new LengthAwarePaginator($pagina, $total, $perPage, $page);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recolectar(User $usuario): Collection
    {
        return collect()
            ->merge($this->perdonesDeRelacion($usuario))
            ->merge($this->solicitudesConciliacion($usuario))
            ->merge($this->solicitudesEdicionCliente($usuario))
            ->merge($this->solicitudesAumentoCredito($usuario))
            ->merge($this->solicitudesTransferenciaCliente($usuario))
            ->merge($this->cambiosEstadoDistribuidora($usuario))
            ->merge($this->altasProveedor($usuario))
            ->merge($this->abonosAutorizados($usuario))
            ->merge($this->cortesGenerados($usuario));
    }

    private function perdonesDeRelacion(User $usuario): Collection
    {
        return RelacionPerdon::query()
            ->where('autorizado_por', $usuario->id)
            ->with('distribuidora')
            ->get()
            ->map(fn (RelacionPerdon $r) => [
                'tipo' => 'perdon_relacion',
                'titulo' => 'Perdón de relación',
                'fecha' => $r->created_at,
                'entidad_id' => $r->relacion_id,
                'descripcion' => "Perdón #{$r->numero_perdon} a {$r->distribuidora?->numero_distribuidora} (relación #{$r->relacion_id}).",
                'estado' => null,
            ]);
    }

    private function solicitudesConciliacion(User $usuario): Collection
    {
        return SolicitudConciliacion::query()
            ->where('autorizado_por', $usuario->id)
            ->get()
            ->map(fn (SolicitudConciliacion $s) => [
                'tipo' => 'solicitud_conciliacion',
                'titulo' => 'Conciliación manual',
                'fecha' => $s->fecha_decision ?? $s->updated_at,
                'entidad_id' => $s->id,
                'descripcion' => "Solicitud de conciliación manual #{$s->id} — {$s->estado}.",
                'estado' => $s->estado,
            ]);
    }

    private function solicitudesEdicionCliente(User $usuario): Collection
    {
        return SolicitudEdicionCliente::query()
            ->where('autorizado_por', $usuario->id)
            ->get()
            ->map(fn (SolicitudEdicionCliente $s) => [
                'tipo' => 'edicion_cliente',
                'titulo' => 'Edición de datos de cliente',
                'fecha' => $s->fecha_decision ?? $s->updated_at,
                'entidad_id' => $s->cliente_id,
                'descripcion' => "Solicitud de edición del cliente #{$s->cliente_id} — {$s->estado}.",
                'estado' => $s->estado,
            ]);
    }

    private function solicitudesAumentoCredito(User $usuario): Collection
    {
        return SolicitudAumentoCredito::query()
            ->where('decidido_por', $usuario->id)
            ->get()
            ->map(fn (SolicitudAumentoCredito $s) => [
                'tipo' => 'aumento_credito',
                'titulo' => 'Aumento de crédito',
                'fecha' => $s->fecha_decision ?? $s->updated_at,
                'entidad_id' => $s->distribuidora_id,
                'descripcion' => "Aumento de crédito de la distribuidora #{$s->distribuidora_id}: solicitado \${$s->monto_solicitado}, otorgado ".($s->monto_otorgado !== null ? "\${$s->monto_otorgado}" : 'rechazado').'.',
                'estado' => $s->estado,
            ]);
    }

    private function solicitudesTransferenciaCliente(User $usuario): Collection
    {
        return SolicitudTransferenciaCliente::query()
            ->where('autorizado_por', $usuario->id)
            ->get()
            ->map(fn (SolicitudTransferenciaCliente $s) => [
                'tipo' => 'transferencia_cliente',
                'titulo' => 'Transferencia de cliente entre distribuidoras',
                'fecha' => $s->fecha_autorizacion ?? $s->updated_at,
                'entidad_id' => $s->cliente_id,
                'descripcion' => "Transferencia del cliente #{$s->cliente_id} — {$s->estado}.",
                'estado' => $s->estado,
            ]);
    }

    private function cambiosEstadoDistribuidora(User $usuario): Collection
    {
        return HistorialEstadoDistribuidora::query()
            ->where('cambiado_por', $usuario->id)
            ->get()
            ->map(fn (HistorialEstadoDistribuidora $h) => [
                'tipo' => 'cambio_estado_distribuidora',
                'titulo' => 'Cambio de estado de distribuidora',
                'fecha' => $h->fecha ?? $h->created_at,
                'entidad_id' => $h->distribuidora_id,
                'descripcion' => "Distribuidora #{$h->distribuidora_id}: {$h->estado_anterior} → {$h->estado_nuevo}.",
                'estado' => $h->estado_nuevo,
            ]);
    }

    private function altasProveedor(User $usuario): Collection
    {
        return SolicitudProveedor::query()
            ->where('gerente_id', $usuario->id)
            ->where('decision_gerente', '!=', 'pendiente')
            ->get()
            ->map(fn (SolicitudProveedor $s) => [
                'tipo' => 'alta_proveedor',
                'titulo' => 'Alta de proveedor (nueva distribuidora)',
                'fecha' => $s->fecha_decision ?? $s->updated_at,
                'entidad_id' => $s->id,
                'descripcion' => "Solicitud de alta #{$s->id} — {$s->decision_gerente}.",
                'estado' => $s->decision_gerente,
            ]);
    }

    private function abonosAutorizados(User $usuario): Collection
    {
        return AbonoConciliacion::query()
            ->where('autorizado_por', $usuario->id)
            ->get()
            ->map(fn (AbonoConciliacion $a) => [
                'tipo' => 'abono_conciliacion_manual',
                'titulo' => 'Conciliación manual de un abono',
                'fecha' => $a->updated_at,
                'entidad_id' => $a->relacion_id,
                'descripcion' => "Abono \${$a->monto} conciliado manualmente contra la relación #{$a->relacion_id}.",
                'estado' => $a->estado,
            ]);
    }

    private function cortesGenerados(User $usuario): Collection
    {
        return Relacion::query()
            ->where('generada_por', $usuario->id)
            ->get()
            ->map(fn (Relacion $r) => [
                'tipo' => 'corte_generado',
                'titulo' => 'Corte generado manualmente',
                'fecha' => $r->created_at,
                'entidad_id' => $r->id,
                'descripcion' => "Corte #{$r->id} generado para la distribuidora #{$r->distribuidora_id} (ref. {$r->referencia_pago}).",
                'estado' => $r->estado,
            ]);
    }
}
