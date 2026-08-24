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
            // Nombre + número, no solo el número: quien autoriza tenía que decidir sobre
            // "DIST-00001 → DIST-EQUIPO-01" sin saber de quién se trataba ninguno de los dos.
            'distribuidora_origen' => $this->whenLoaded('distribuidoraOrigen', fn () => $this->etiquetaDistribuidora($this->distribuidoraOrigen)),
            'distribuidora_destino_id' => $this->distribuidora_destino_id,
            'distribuidora_destino' => $this->whenLoaded('distribuidoraDestino', fn () => $this->etiquetaDistribuidora($this->distribuidoraDestino)),
            // Solo la destino puede confirmar/declinar (ver decidirAceptacion) -- ahora que la
            // origen también ve la solicitud, la UI necesita saber a quién mostrarle esos botones.
            'soy_destino' => $this->distribuidora_destino_id === $request->user()?->distribuidora?->id,
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

    /**
     * "Nombre (DIST-000XX)" -- el número solo no identifica a nadie de un vistazo, y el nombre
     * solo no basta para distinguir dos distribuidoras del mismo titular.
     */
    private function etiquetaDistribuidora(?\App\Models\Distribuidora $distribuidora): ?string
    {
        if (! $distribuidora) {
            return null;
        }

        $nombre = $distribuidora->nombre;

        return $nombre
            ? "{$nombre} ({$distribuidora->numero_distribuidora})"
            : $distribuidora->numero_distribuidora;
    }
}
