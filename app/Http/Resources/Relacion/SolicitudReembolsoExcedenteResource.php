<?php

declare(strict_types=1);

namespace App\Http\Resources\Relacion;

use App\Models\SolicitudReembolsoExcedente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudReembolsoExcedente
 */
final class SolicitudReembolsoExcedenteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vale_id' => $this->vale_id,
            'vale_cliente' => $this->whenLoaded('vale', function () {
                $datos = $this->vale?->cliente?->datosPersonales;

                return $datos ? trim($datos->nombre.' '.$datos->apellido_paterno.' '.($datos->apellido_materno ?? '')) : null;
            }),
            'distribuidora_id' => $this->distribuidora_id,
            'distribuidora' => $this->whenLoaded('distribuidora', function () {
                $nombre = $this->distribuidora?->nombre;

                return $nombre
                    ? "{$nombre} ({$this->distribuidora->numero_distribuidora})"
                    : $this->distribuidora?->numero_distribuidora;
            }),
            'monto' => (float) $this->monto,
            'solicitado_por' => $this->solicitado_por,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante?->name),
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'autorizado_por' => $this->autorizado_por,
            'autorizador' => $this->whenLoaded('autorizador', fn () => $this->autorizador?->name),
            'comentario_autorizacion' => $this->comentario_autorizacion,
            'fecha_decision' => $this->fecha_decision?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
