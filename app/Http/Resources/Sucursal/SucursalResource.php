<?php

declare(strict_types=1);

namespace App\Http\Resources\Sucursal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SucursalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'codigo' => $this->codigo,
            'es_matriz' => $this->es_matriz,
            'is_active' => $this->is_active,
        ];
    }
}
