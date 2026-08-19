<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\SolicitudAumentoCredito;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudAumentoCredito
 */
final class SolicitudAumentoCreditoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidora_id' => $this->distribuidora_id,
            'distribuidora' => $this->whenLoaded('distribuidora', fn () => $this->distribuidora?->numero_distribuidora),
            'solicitado_por' => $this->solicitado_por,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante?->name),
            'limite_credito_anterior' => (float) $this->limite_credito_anterior,
            'monto_solicitado' => (float) $this->monto_solicitado,
            'monto_otorgado' => $this->monto_otorgado !== null ? (float) $this->monto_otorgado : null,
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'decidido_por' => $this->decidido_por,
            'decisor' => $this->whenLoaded('decisor', fn () => $this->decisor?->name),
            'comentario_decision' => $this->comentario_decision,
            'fecha_decision' => $this->fecha_decision?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
