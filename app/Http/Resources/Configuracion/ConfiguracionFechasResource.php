<?php

declare(strict_types=1);

namespace App\Http\Resources\Configuracion;

use App\Models\ConfiguracionFechas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConfiguracionFechas
 */
final class ConfiguracionFechasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sucursal_id' => $this->sucursal_id,
            'sucursal' => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'nombre' => $this->sucursal->nombre,
                'codigo' => $this->sucursal->codigo,
            ] : null,
            'es_default_global' => $this->sucursal_id === null,
            'dia_corte' => $this->dia_corte,
            'dia_limite_pago' => $this->dia_limite_pago,
            'dias_pago_anticipado' => $this->dias_pago_anticipado,
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
