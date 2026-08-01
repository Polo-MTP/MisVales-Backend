<?php

declare(strict_types=1);

namespace App\Http\Resources\AltaProveedor;

use App\Models\SolicitudProveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudProveedor
 */
final class SolicitudProveedorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'cumple' => $this->cumple,
            'comentario_verificador' => $this->comentario_verificador,
            'fecha_verificacion' => $this->fecha_verificacion?->toIso8601String(),
            'decision_gerente' => $this->decision_gerente,
            'comentario_gerente' => $this->comentario_gerente,
            'limite_credito_asignado' => $this->limite_credito_asignado,
            'fecha_decision' => $this->fecha_decision?->toIso8601String(),
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
            'coordinador' => [
                'id' => $this->coordinador?->id,
                'name' => $this->coordinador?->name,
                'email' => $this->coordinador?->email,
            ],
            'verificador' => [
                'id' => $this->verificador?->id,
                'name' => $this->verificador?->name,
                'email' => $this->verificador?->email,
            ],
            'gerente' => [
                'id' => $this->gerente?->id,
                'name' => $this->gerente?->name,
                'email' => $this->gerente?->email,
            ],
            'evidencias' => EvidenciaResource::collection($this->whenLoaded('evidencias')),
            'logs_auditoria' => LogNuevoProveedorResource::collection($this->whenLoaded('logs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
