<?php

declare(strict_types=1);

namespace App\Http\Resources\Distribuidora;

use App\Models\SolicitudEdicionCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
            'cliente_nombre' => $this->whenLoaded('cliente', function () {
                $datos = $this->cliente?->datosPersonales;

                return $datos ? trim("{$datos->nombre} {$datos->apellido_paterno} {$datos->apellido_materno}") : null;
            }),
            'solicitado_por' => $this->solicitado_por,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante?->name),
            'sucursal_id' => $this->sucursal_id,
            'campos_propuestos' => Arr::except($this->campos_propuestos, ['_snapshot']),
            // Valor ACTUAL del cliente para esos mismos campos, para que quien autoriza vea el
            // antes/después uno junto al otro sin tener que ir a comparar a mano contra el
            // perfil del cliente. Se lee en vivo (no un snapshot guardado al solicitar) porque
            // es justo lo que aplicar() también compara para detectar ediciones concurrentes --
            // el "antes" que importa es el de ahora mismo, no el de cuando la cajera pidió esto.
            'antes' => $this->whenLoaded('cliente', fn () => [
                'datos_personales' => Arr::only(
                    $this->cliente?->datosPersonales?->toArray() ?? [],
                    array_keys($this->campos_propuestos['datos_personales'] ?? [])
                ),
                'direccion' => Arr::only(
                    $this->cliente?->datosPersonales?->direccion?->toArray() ?? [],
                    array_keys($this->campos_propuestos['direccion'] ?? [])
                ),
            ]),
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
