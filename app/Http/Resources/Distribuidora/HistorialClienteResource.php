<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\HistorialClienteDistr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HistorialClienteDistr
 */
final class HistorialClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidor_id' => $this->distribuidor_id,
            'distribuidora_numero' => $this->distribuidora?->numero_distribuidora,
            'cliente_id' => $this->cliente_id,
            'fecha_inicio' => $this->fecha_inicio?->toIso8601String(),
            'fecha_fin' => $this->fecha_fin?->toIso8601String(),
            'activo_con_distribuidora' => $this->fecha_fin === null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
