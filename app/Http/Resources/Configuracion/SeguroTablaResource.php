<?php

declare(strict_types=1);

namespace App\Http\Resources\Configuracion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SeguroTablaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monto_desde' => $this->monto_desde,
            'monto_hasta' => $this->monto_hasta,
            'seguro_monto' => $this->seguro_monto,
            'activo' => $this->activo,
        ];
    }
}
