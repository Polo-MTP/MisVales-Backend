<?php

namespace App\Http\Resources\Productos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monto' => $this->monto,
            'descripcion' => $this->descripcion,
            'activo' => $this->activo,
            'creado_por' => $this->creador?->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}