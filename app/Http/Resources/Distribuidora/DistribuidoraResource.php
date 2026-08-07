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
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'numero_distribuidora' => $this->numero_distribuidora,
            'razon_social' => $this->razon_social,
            'rfc' => $this->rfc,
            'limite_credito' => $this->limite_credito,
            'credito_disponible' => $this->credito_disponible,
            'categoria' => new CategoriaDistribuidoraResource($this->whenLoaded('categoria')),
            'puntos_acumulados' => $this->puntos_acumulados,
            'estado' => $this->estado,
            'sucursal' => [
                'id' => $this->sucursal?->id,
                'nombre' => $this->sucursal?->nombre,
            ],
            'coordinador' => [
                'id' => $this->coordinador?->id,
                'name' => $this->coordinador?->name,
            ],
            'verificador' => [
                'id' => $this->verificador?->id,
                'name' => $this->verificador?->name,
            ],
            'datos_personales' => [
                'nombre' => $this->usuario?->datosPersonales?->nombre,
                'apellido_paterno' => $this->usuario?->datosPersonales?->apellido_paterno,
                'apellido_materno' => $this->usuario?->datosPersonales?->apellido_materno,
                'curp' => $this->usuario?->datosPersonales?->curp,
                'fecha_nacimiento' => $this->usuario?->datosPersonales?->fecha_nacimiento?->toDateString(),
                'lugar_nacimiento' => $this->usuario?->datosPersonales?->lugar_nacimiento,
                'direccion' => [
                    'calle' => $this->usuario?->datosPersonales?->direccion?->calle,
                    'colonia' => $this->usuario?->datosPersonales?->direccion?->colonia,
                    'numero_ext' => $this->usuario?->datosPersonales?->direccion?->numero_ext,
                    'numero_int' => $this->usuario?->datosPersonales?->direccion?->numero_int,
                    'codigo_postal' => $this->usuario?->datosPersonales?->direccion?->codigo_postal,
                    'estado' => $this->usuario?->datosPersonales?->direccion?->estado,
                    'ciudad' => $this->usuario?->datosPersonales?->direccion?->ciudad,
                ],
            ],
            'datos_extras' => new DistribuidorDatosExtrasResource($this->whenLoaded('datosExtras')),
            'comentarios_verificador' => $this->comentarios_verificador,
            'fecha_aprobacion' => $this->fecha_aprobacion?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
