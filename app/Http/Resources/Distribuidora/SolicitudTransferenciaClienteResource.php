<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\SolicitudTransferenciaCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudTransferenciaCliente
 */
final class SolicitudTransferenciaClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cliente_id' => $this->cliente_id,
            'cliente_nombre' => $this->whenLoaded('cliente', function () {
                $datos = $this->cliente?->datosPersonales;

                return $datos ? trim("{$datos->nombre} {$datos->apellido_paterno} {$datos->apellido_materno}") : null;
            }),
            'distribuidora_origen_id' => $this->distribuidora_origen_id,
            'distribuidora_origen' => $this->whenLoaded('distribuidoraOrigen', fn () => $this->distribuidoraOrigen?->numero_distribuidora),
            'distribuidora_destino_id' => $this->distribuidora_destino_id,
            'distribuidora_destino' => $this->whenLoaded('distribuidoraDestino', fn () => $this->distribuidoraDestino?->numero_distribuidora),
            'solicitado_por' => $this->solicitado_por,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante?->name),
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'autorizado_por' => $this->autorizado_por,
            'autorizador' => $this->whenLoaded('autorizador', fn () => $this->autorizador?->name),
            'comentario_autorizacion' => $this->comentario_autorizacion,
            'fecha_autorizacion' => $this->fecha_autorizacion?->toIso8601String(),
            'fecha_aceptacion' => $this->fecha_aceptacion?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
