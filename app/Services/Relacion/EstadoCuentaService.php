<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\Distribuidora;
use App\Models\RelacionDetalle;
use Illuminate\Support\Collection;

/**
 * Estado de cuenta acumulado de una distribuidora: agrupa por cliente todas las cuotas que
 * sigue debiendo, a través de TODOS sus cortes (no solo el más reciente) -- así, si un cliente
 * no pagó la quincena 1 y ya se generó la quincena 2 (automático o vía "Generar Corte del
 * Día"), aquí aparece su saldo sumado en un solo lugar en vez de tener que ir corte por
 * corte. No cambia cómo se generan los cortes (cada uno sigue siendo su propia Relacion, ver
 * RelacionCalculoService) -- es una vista de solo lectura sobre lo que ya existe, siempre en
 * vivo: se actualiza sola en cuanto se genera un corte nuevo, sin ninguna acción aparte.
 *
 * El pago en sí sigue viniendo de la conciliación bancaria de siempre (ConciliacionBancariaService):
 * cada cuota trae su propio 'concepto' (para pagarla por separado) y la 'referencia_pago' del
 * corte al que pertenece (para pagar ese corte completo) -- esta vista solo hace visible, de
 * un vistazo, cuánto suma cada cliente en total.
 */
final class EstadoCuentaService
{
    /**
     * @return array{clientes: Collection<int, array<string, mixed>>, total_pendiente: float}
     */
    public function obtenerPorDistribuidora(Distribuidora $distribuidora): array
    {
        $detalles = RelacionDetalle::query()
            ->whereHas('relacion', fn ($q) => $q->where('distribuidora_id', $distribuidora->id))
            ->where('estado', '!=', 'pagado')
            ->with(['cliente.datosPersonales', 'vale.producto', 'relacion'])
            ->get();

        $clientes = $detalles
            ->groupBy('cliente_id')
            ->map(function (Collection $detallesDelCliente) {
                /** @var RelacionDetalle $primero */
                $primero = $detallesDelCliente->first();
                $cliente = $primero->cliente;

                return [
                    'cliente_id' => $primero->cliente_id,
                    'nombre' => trim(($cliente?->datosPersonales?->nombre ?? '').' '.($cliente?->datosPersonales?->apellido_paterno ?? '')),
                    'saldo_pendiente' => round((float) $detallesDelCliente->sum(fn (RelacionDetalle $d) => (float) $d->total - (float) $d->pago), 2),
                    'cuotas' => $detallesDelCliente->map(fn (RelacionDetalle $d) => [
                        'relacion_detalle_id' => $d->id,
                        'relacion_id' => $d->relacion_id,
                        'referencia_pago' => $d->relacion?->referencia_pago,
                        'concepto' => $d->concepto,
                        'vale_id' => $d->vale_id,
                        'producto' => $d->vale?->producto?->descripcion,
                        'cuota' => "{$d->cuota_numero}/{$d->cuotas_totales}",
                        'fecha_corte' => $d->relacion?->fecha_corte?->toDateString(),
                        'total' => (float) $d->total,
                        'pago' => (float) $d->pago,
                        'saldo' => round((float) $d->total - (float) $d->pago, 2),
                        'estado' => $d->estado,
                    ])->values(),
                ];
            })
            ->sortByDesc('saldo_pendiente')
            ->values();

        return [
            'clientes' => $clientes,
            'total_pendiente' => round((float) $clientes->sum('saldo_pendiente'), 2),
        ];
    }
}
