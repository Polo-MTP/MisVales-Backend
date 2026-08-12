<?php

declare(strict_types=1);

namespace App\Http\Resources\Notificacion;

use App\Models\Notificacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notificacion
 */
final class NotificacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accion' => $this->accion,
            'recurso' => $this->recurso,
            'sucursal' => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'nombre' => $this->sucursal->nombre,
            ] : null,
            'usuario' => $this->usuario ? [
                'id' => $this->usuario->id,
                'name' => $this->usuario->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
