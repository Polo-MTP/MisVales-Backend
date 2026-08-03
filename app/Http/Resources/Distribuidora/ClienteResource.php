<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cliente
 */
final class ClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'datos_personales' => [
                'id' => $this->datosPersonales?->id,
                'nombre' => $this->datosPersonales?->nombre,
                'apellido_paterno' => $this->datosPersonales?->apellido_paterno,
                'apellido_materno' => $this->datosPersonales?->apellido_materno,
                'curp' => $this->datosPersonales?->curp,
                'fecha_nacimiento' => $this->datosPersonales?->fecha_nacimiento?->toDateString(),
                'lugar_nacimiento' => $this->datosPersonales?->lugar_nacimiento,
                'direccion' => [
                    'id' => $this->datosPersonales?->direccion?->id,
                    'calle' => $this->datosPersonales?->direccion?->calle,
                    'colonia' => $this->datosPersonales?->direccion?->colonia,
                    'numero_ext' => $this->datosPersonales?->direccion?->numero_ext,
                    'numero_int' => $this->datosPersonales?->direccion?->numero_int,
                    'codigo_postal' => $this->datosPersonales?->direccion?->codigo_postal,
                    'estado' => $this->datosPersonales?->direccion?->estado,
                    'ciudad' => $this->datosPersonales?->direccion?->ciudad,
                ],
            ],
            'historial_asignacion' => HistorialClienteResource::collection($this->whenLoaded('historialDistribuidoras')),
        ];
    }
}
