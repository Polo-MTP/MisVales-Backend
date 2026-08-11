<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\SolicitudEdicionCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudEdicionCliente
 */
final class SolicitudEdicionClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cliente_id' => $this->cliente_id,
            'solicitado_por' => $this->solicitado_por,
            'sucursal_id' => $this->sucursal_id,
            'campos_propuestos' => $this->campos_propuestos,
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'autorizado_por' => $this->autorizado_por,
            'comentario_autorizacion' => $this->comentario_autorizacion,
            'fecha_decision' => $this->fecha_decision?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
