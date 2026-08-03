<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\HistorialEstadoDistribuidora;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HistorialEstadoDistribuidora
 */
final class HistorialEstadoDistribuidoraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidora_id' => $this->distribuidora_id,
            'estado_anterior' => $this->estado_anterior,
            'estado_nuevo' => $this->estado_nuevo,
            'motivo' => $this->motivo,
            'cambiado_por' => [
                'id' => $this->cambiadoPor?->id,
                'name' => $this->cambiadoPor?->name,
                'email' => $this->cambiadoPor?->email,
            ],
            'fecha' => $this->fecha?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
