<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DistribuidorDatosExtrasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'datos_familiares' => $this->datos_familiares,
            'datos_vehiculos' => $this->datos_vehiculos,
            'datos_vivienda' => $this->datos_vivienda,
            'referencia_laboral' => $this->referencia_laboral,
        ];
    }
}
