<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DistribuidorDatosPersonalesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_representante' => $this->nombre_representante,
            'apellido_paterno' => $this->apellido_paterno,
            'apellido_materno' => $this->apellido_materno,
            'curp' => $this->curp,
            'rfc_personal' => $this->rfc_personal,
            'fecha_nacimiento' => $this->fecha_nacimiento?->toDateString(),
            'calle' => $this->calle,
            'numero' => $this->numero,
            'colonia' => $this->colonia,
            'cp' => $this->cp,
            'estado' => $this->estado,
            'ciudad' => $this->ciudad,
            'datos_familiares' => $this->datos_familiares,
            'datos_vehiculos' => $this->datos_vehiculos,
            'datos_vivienda' => $this->datos_vivienda,
        ];
    }
}