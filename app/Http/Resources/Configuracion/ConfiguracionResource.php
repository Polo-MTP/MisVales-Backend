<?php

declare(strict_types=1);

namespace App\Http\Resources\Configuracion;

use App\Models\Configuracion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Configuracion
 */
final class ConfiguracionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clave' => $this->clave,
            'valor' => $this->valor,
            'valor_casteado' => $this->valor_casteado,
            'tipo_dato' => $this->tipo_dato,
            'vigente_desde' => $this->vigente_desde?->toDateString(),
            'vigente_hasta' => $this->vigente_hasta?->toDateString(),
            'es_vigente' => $this->vigente_hasta === null,
            'modificado_por' => [
                'id' => $this->modificadoPor?->id,
                'name' => $this->modificadoPor?->name,
                'email' => $this->modificadoPor?->email,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
