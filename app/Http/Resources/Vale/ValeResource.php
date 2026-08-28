<?php

declare(strict_types=1);

namespace App\Http\Resources\Vale;

use App\Models\Vale;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vale
 */
final class ValeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidora_id' => $this->distribuidora_id,
            'distribuidora' => $this->distribuidora ? [
                'id' => $this->distribuidora->id,
                'nombre' => $this->distribuidora->nombre,
                'numero_distribuidora' => $this->distribuidora->numero_distribuidora,
            ] : null,
            // La cajera necesita ver estos datos para compararlos contra la INE y el
            // comprobante de domicilio físicos antes de marcar el checklist de validación.
            'cliente' => $this->cliente ? [
                'id' => $this->cliente->id,
                'nombre' => trim(($this->cliente->datosPersonales?->nombre ?? '').' '.($this->cliente->datosPersonales?->apellido_paterno ?? '').' '.($this->cliente->datosPersonales?->apellido_materno ?? '')),
                'curp' => $this->cliente->datosPersonales?->curp,
                // Igual que ClienteResource: nunca la CLABE completa, solo los últimos 4 dígitos
                // para que la cajera sepa si ya hay una registrada antes de pedir una nueva.
                'clabe_ultimos4' => $this->cliente->clabe ? mb_substr((string) $this->cliente->clabe, -4) : null,
                'direccion' => $this->cliente->datosPersonales?->direccion ? trim(
                    $this->cliente->datosPersonales->direccion->calle
                        .' '.$this->cliente->datosPersonales->direccion->numero_ext
                        .', '.$this->cliente->datosPersonales->direccion->colonia
                        .', CP '.$this->cliente->datosPersonales->direccion->codigo_postal
                        .', '.$this->cliente->datosPersonales->direccion->ciudad
                        .', '.$this->cliente->datosPersonales->direccion->estado
                ) : null,
            ] : null,
            'producto' => $this->producto ? [
                'id' => $this->producto->id,
                'monto' => $this->producto->monto,
                'descripcion' => $this->producto->descripcion,
            ] : null,
            'monto' => $this->monto,
            'quincenas' => $this->quincenas,
            // Congelado al solicitarse (ver ValeService::solicitar()) -- no cambia aunque la
            // tabla de seguros cambie después. null solo en vales de antes de este cambio.
            'seguro_monto' => $this->seguro_monto !== null ? (float) $this->seguro_monto : null,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'activo' => $this->activo,
            'fecha_solicitud' => $this->fecha_solicitud?->toIso8601String(),
            'validado_por' => $this->validado_por,
            'fecha_validacion' => $this->fecha_validacion?->toIso8601String(),
            'ine_verificada' => $this->ine_verificada,
            'comprobante_domicilio_verificado' => $this->comprobante_domicilio_verificado,
            'fecha_autorizacion' => $this->fecha_autorizacion?->toIso8601String(),
            'numero_transferencia' => $this->numero_transferencia,
            // Saldo a favor de ESTE vale (no de la distribuidora) por un pago de más en
            // conciliación -- ver ExcedenteConciliacionService. Se aplica solo a las cuotas
            // futuras de este mismo vale; si ya está 'pagado' y aquí sigue habiendo algo, ya no
            // hay ninguna cuota que lo consuma sola (ver SolicitudReembolsoExcedente).
            'saldo_excedente' => (float) $this->saldo_excedente,
            'created_at' => $this->created_at?->toIso8601String(),
            // Suma de todas las cuotas de este vale que ya entraron a un corte -- lo que
            // realmente le toca pagar/lo que ya pagó en total, sin tener que sumar "cortes" a
            // mano en el front. Antes solo se veía el total por cuota individual; para saber si
            // el vale iba al corriente o atrasado en su conjunto había que sumarlas una por una.
            // Se excluyen las 'arrastrada': su saldo ya vive dentro del 'total' de la cuota que
            // las absorbió (ver RelacionCalculoService::calcularDetalleVale()) -- sumarlas
            // también aquí lo contaría dos veces.
            'total_acumulado_a_pagar' => round((float) $this->relacionDetalles->where('estado', '!=', 'arrastrada')->sum('total'), 2),
            'total_acumulado_pagado' => round((float) $this->relacionDetalles->sum('pago'), 2),
            // Cortes (relaciones) donde ya se facturó alguna cuota de este vale -- antes no había
            // forma de rastrear, desde el vale, en qué corte(s) quedó incluido.
            'cortes' => $this->relacionDetalles->map(fn ($detalle) => [
                'relacion_id' => $detalle->relacion_id,
                'referencia_pago' => $detalle->relacion?->referencia_pago,
                // Si el corte junta más de un vale y se paga cada uno por separado, esto es lo
                // que va en "Concepto" de la transferencia para que se aplique a este vale y
                // no a otro del mismo corte (ver RelacionCalculoService::construirConceptoVale).
                'concepto' => $detalle->concepto,
                'fecha_corte' => $detalle->relacion?->fecha_corte?->toDateString(),
                'cuota' => "{$detalle->cuota_numero}/{$detalle->cuotas_totales}",
                'estado_cuota' => $detalle->estado,
                'total' => $detalle->total,
                'pago' => $detalle->pago,
                // Si esta cuota estaba sin liquidar cuando se generó la siguiente del mismo
                // vale, su saldo se movió allá (estado_cuota='arrastrada', total ya en 0) --
                // este es cuánto absorbió ESTA cuota de la que la precedió, ya incluido en 'total'.
                'arrastre' => $detalle->arrastre,
            ])->values(),
            // Mientras el vale no entre a ningún corte, 'cortes' viene vacío y no hay forma de
            // saber cuánto va a tocar pagar por quincena -- ese desglose real recién se calcula
            // al generarse el corte (RelacionCalculoService::calcularDetalleVale). Aquí se
            // reutiliza la misma fórmula (simularPagoQuincenal) para dar un estimado mientras
            // tanto, sin que el front tenga que ir a pedirlo aparte a /productos/{id}/simulacion.
            'estimacion' => $this->relacionDetalles->isEmpty() ? $this->construirEstimacion() : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function construirEstimacion(): ?array
    {
        $quincenas = (int) ($this->quincenas ?? $this->producto?->quincenas ?? 0);

        if ($quincenas < 1) {
            return null;
        }

        $resultado = app(RelacionCalculoService::class)->simularPagoQuincenal(
            (float) $this->monto,
            $quincenas,
            $this->distribuidora,
            $this->seguro_monto !== null ? (float) $this->seguro_monto : null,
        );

        return [
            ...$resultado,
            'nota' => 'Estimado si paga puntual, con las reglas vigentes hoy. En cuanto este vale entre a un corte, "cortes" trae el desglose real.',
        ];
    }
}
