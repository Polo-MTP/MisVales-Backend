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
            // Nunca el número completo: es un dato bancario sensible (cifrado en BD). Solo se
            // exponen los últimos 4 dígitos, para que el front pueda confirmar "ya la tenemos
            // registrada" sin tener que volver a mostrar/transmitir la tarjeta completa.
            'numero_tarjeta_ultimos4' => $this->numero_tarjeta ? mb_substr((string) $this->numero_tarjeta, -4) : null,
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
                    'latitud' => $this->datosPersonales?->direccion?->latitud,
                    'longitud' => $this->datosPersonales?->direccion?->longitud,
                ],
            ],
            'historial_asignacion' => HistorialClienteResource::collection($this->whenLoaded('historialDistribuidoras')),
        ];
    }
}
