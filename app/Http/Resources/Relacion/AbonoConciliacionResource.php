<?php

declare(strict_types=1);

namespace App\Http\Resources\Relacion;

use App\Models\AbonoConciliacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AbonoConciliacion
 */
final class AbonoConciliacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'relacion_id' => $this->relacion_id,
            'referencia_leida' => $this->referencia_leida,
            'monto' => $this->monto,
            'folio_pago' => $this->folio_pago,
            'fecha_pago' => $this->fecha_pago?->toDateString(),
            'hora_pago' => $this->hora_pago,
            'tipo_pago' => $this->tipo_pago,
            'estado' => $this->estado,
            'convenio_bancario' => $this->convenioBancario?->banco,
            'autorizado_por' => $this->autorizadoPor?->name,
            'motivo_manual' => $this->motivo_manual,
            'queja' => $this->queja_por ? [
                'reportado_por' => $this->quejaPor?->name,
                'motivo' => $this->queja_motivo,
                'fecha' => $this->queja_fecha?->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
