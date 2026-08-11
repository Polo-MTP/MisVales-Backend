<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\PuntoMovimiento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PuntoMovimiento
 */
final class PuntoMovimientoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidora_id' => $this->distribuidora_id,
            'tipo' => $this->tipo,
            'cantidad' => $this->cantidad,
            'motivo' => $this->motivo,
            'registrado_por' => $this->registrado_por,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
