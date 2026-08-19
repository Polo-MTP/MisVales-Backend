<?php

declare(strict_types=1);

namespace App\Http\Resources\Vale;

use App\Models\Vale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vale
 */
final class ValeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distribuidora_id' => $this->distribuidora_id,
            'distribuidora' => $this->distribuidora ? [
                'id' => $this->distribuidora->id,
                'razon_social' => $this->distribuidora->razon_social,
                'numero_distribuidora' => $this->distribuidora->numero_distribuidora,
            ] : null,
            // La cajera necesita ver estos datos para compararlos contra la INE y el
            // comprobante de domicilio físicos antes de marcar el checklist de validación.
            'cliente' => $this->cliente ? [
                'id' => $this->cliente->id,
                'nombre' => trim(($this->cliente->datosPersonales?->nombre ?? '').' '.($this->cliente->datosPersonales?->apellido_paterno ?? '').' '.($this->cliente->datosPersonales?->apellido_materno ?? '')),
                'curp' => $this->cliente->datosPersonales?->curp,
                'direccion' => $this->cliente->datosPersonales?->direccion ? trim(
                    $this->cliente->datosPersonales->direccion->calle
                        .' '.$this->cliente->datosPersonales->direccion->numero_ext
                        .', '.$this->cliente->datosPersonales->direccion->colonia
                        .', CP '.$this->cliente->datosPersonales->direccion->codigo_postal
                        .', '.$this->cliente->datosPersonales->direccion->ciudad
                        .', '.$this->cliente->datosPersonales->direccion->estado
                ) : null,
            ] : null,
            'producto' => $this->producto ? [
                'id' => $this->producto->id,
                'monto' => $this->producto->monto,
                'descripcion' => $this->producto->descripcion,
            ] : null,
            'monto' => $this->monto,
            'quincenas' => $this->quincenas,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'activo' => $this->activo,
            'fecha_solicitud' => $this->fecha_solicitud?->toIso8601String(),
            'validado_por' => $this->validado_por,
            'fecha_validacion' => $this->fecha_validacion?->toIso8601String(),
            'ine_verificada' => $this->ine_verificada,
            'comprobante_domicilio_verificado' => $this->comprobante_domicilio_verificado,
            'fecha_autorizacion' => $this->fecha_autorizacion?->toIso8601String(),
            'numero_transferencia' => $this->numero_transferencia,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
