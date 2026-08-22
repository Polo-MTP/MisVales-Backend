<?php

declare(strict_types=1);

namespace App\Http\Resources\Relacion;

use App\Models\RelacionDetalle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RelacionDetalle
 */
final class RelacionDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vale_id' => $this->vale_id,
            // Identificador único de esta cuota dentro del corte -- lo que la distribuidora
            // pone en "Concepto" de su transferencia si paga este vale por separado de los
            // demás que compartan el mismo referencia_pago del corte.
            'concepto' => $this->concepto,
            'cliente' => [
                'id' => $this->cliente?->id,
                'nombre' => trim(($this->cliente?->datosPersonales?->nombre ?? '').' '.($this->cliente?->datosPersonales?->apellido_paterno ?? '')),
            ],
            'producto' => $this->producto?->descripcion,
            'cuota' => "{$this->cuota_numero}/{$this->cuotas_totales}",
            'capital' => $this->capital,
            'comision' => $this->comision,
            'interes' => $this->interes,
            'seguro' => $this->seguro,
            'categoria' => $this->categoria,
            'recargo' => $this->recargo,
            'pago' => $this->pago,
            'total' => $this->total,
            'estado' => $this->estado,
        ];
    }
}
