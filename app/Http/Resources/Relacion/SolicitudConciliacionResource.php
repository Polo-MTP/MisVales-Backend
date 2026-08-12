<?php

declare(strict_types=1);

namespace App\Http\Resources\Relacion;

use App\Models\SolicitudConciliacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudConciliacion
 */
final class SolicitudConciliacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'abono_conciliacion_id' => $this->abono_conciliacion_id,
            'abono_referencia' => $this->whenLoaded('abono', fn () => $this->abono?->referencia_leida),
            'abono_monto' => $this->whenLoaded('abono', fn () => $this->abono?->monto),
            'relacion_id' => $this->relacion_id,
            'relacion_referencia' => $this->whenLoaded('relacion', fn () => $this->relacion?->referencia_pago),
            'solicitado_por' => $this->solicitado_por,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante?->name),
            'sucursal_id' => $this->sucursal_id,
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'autorizado_por' => $this->autorizado_por,
            'autorizador' => $this->whenLoaded('autorizador', fn () => $this->autorizador?->name),
            'comentario_autorizacion' => $this->comentario_autorizacion,
            'fecha_decision' => $this->fecha_decision?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
