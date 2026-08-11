<?php

declare(strict_types=1);

namespace App\Http\Resources\AltaProveedor;

use App\Models\Evidencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Evidencia
 */
final class EvidenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'solicitud_id' => $this->solicitud_id,
            'tipo_documento' => $this->tipo_documento,
            'url_archivo' => $this->url_archivo,
            'subido_por' => $this->usuario?->name,
            'fecha_subida' => $this->fecha_subida?->toIso8601String(),
        ];
    }
}
