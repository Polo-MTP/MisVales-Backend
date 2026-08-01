<?php

declare(strict_types=1);

namespace App\Http\Resources\AltaProveedor;

use App\Models\LogNuevoProveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LogNuevoProveedor
 */
final class LogNuevoProveedorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entidad_tipo' => $this->entidad_tipo,
            'entidad_id' => $this->entidad_id,
            'campo' => $this->campo,
            'valor_anterior' => $this->valor_anterior,
            'valor_nuevo' => $this->valor_nuevo,
            'modificado_por' => $this->usuario?->name,
            'fecha_hora' => $this->fecha_hora?->toIso8601String(),
            'dispositivo' => $this->dispositivo,
            'accion' => $this->accion,
            'motivo' => $this->motivo,
        ];
    }
}
