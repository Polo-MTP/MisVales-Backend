<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\Distribuidora;
use App\Http\Resources\Distribuidora\CategoriaDistribuidoraResource;
use App\Http\Resources\Distribuidora\DistribuidorDatosPersonalesResource;
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
            'datos_personales' => new DistribuidorDatosPersonalesResource($this->whenLoaded('datosPersonales')),
            'comentarios_verificador' => $this->comentarios_verificador,
            'fecha_aprobacion' => $this->fecha_aprobacion?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}