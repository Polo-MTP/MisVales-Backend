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
            'relacion_id' => $this->relacion_id,
            'solicitado_por' => $this->solicitado_por,
            'sucursal_id' => $this->sucursal_id,
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'autorizado_por' => $this->autorizado_por,
            'comentario_autorizacion' => $this->comentario_autorizacion,
            'fecha_decision' => $this->fecha_decision?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
