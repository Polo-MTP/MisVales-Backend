<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CategoriaDistribuidoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'porcentaje_comision' => $this->porcentaje_comision,
            'descripcion' => $this->descripcion,
            'activo' => $this->activo,
        ];
    }
}