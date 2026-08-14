<?php

declare(strict_types=1);

namespace App\Http\Resources\Relacion;

use App\Models\Relacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Relacion
 */
final class RelacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidora_id' => $this->distribuidora_id,
            'distribuidora' => [
                'numero_distribuidora' => $this->distribuidora?->numero_distribuidora,
                'razon_social' => $this->distribuidora?->razon_social,
            ],
            'sucursal' => $this->sucursal?->nombre,
            'referencia_pago' => $this->referencia_pago,
            'fecha_corte' => $this->fecha_corte?->toDateString(),
            'fecha_limite_pago' => $this->fecha_limite_pago?->toDateString(),
            'fecha_pago_anticipado_desde' => $this->fecha_pago_anticipado_desde?->toDateString(),
            'fecha_pago_anticipado_hasta' => $this->fecha_pago_anticipado_hasta?->toDateString(),
            'limite_credito_snapshot' => $this->limite_credito_snapshot,
            'categoria_snapshot' => $this->categoriaSnapshot?->nombre,
            'porcentaje_comision_snapshot' => $this->porcentaje_comision_snapshot,
            'totales' => [
                'capital' => $this->total_capital,
                'comision' => $this->total_comision,
                'interes' => $this->total_interes,
                'seguro' => $this->total_seguro,
                'recargos' => $this->total_recargos,
                'a_pagar' => $this->total_a_pagar,
                'abonado' => $this->total_abonado,
                'saldo_pendiente' => $this->saldo_pendiente,
            ],
            'puntos_generados' => $this->puntos_generados,
            'estado' => $this->estado,
            'detalles' => RelacionDetalleResource::collection($this->whenLoaded('detalles')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
