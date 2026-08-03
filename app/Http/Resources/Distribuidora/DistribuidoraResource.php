<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\Distribuidora;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Distribuidora
 */
final class DistribuidoraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'numero_distribuidora' => $this->numero_distribuidora,
            'limite_credito' => $this->limite_credito,
            'credito_disponible' => $this->credito_disponible,
            'categoria_id' => $this->categoria_id,
            'puntos_acumulados' => $this->puntos_acumulados,
            'estado' => $this->estado,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
