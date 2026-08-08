<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporte;

use App\Http\Controllers\Api\ApiController;
use App\Models\Distribuidora;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReporteController extends ApiController
{
    /**
     * "Distribuidoras Morosas y saldos" (ver documento de análisis de cálculo de relación).
     */
    public function morosos(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $query = Distribuidora::query()
            ->with('sucursal')
            ->whereHas('relaciones', fn ($q) => $q->whereIn('estado', ['vencida', 'en_perdida']));

        // Un Gerente de Sucursal solo ve morosidad de su propia sucursal; Gerente General/Administrador ven todo.
        if ($usuario->role?->name === 'Gerente de Sucursal') {
            $query->where('sucursal_id', $usuario->sucursal_id);
        } elseif ($usuario->role?->name === 'Coordinador') {
            $query->where('coordinador_id', $usuario->id);
        }

        $distribuidoras = $query->get()->map(function (Distribuidora $distribuidora) {
            $relacionesMorosas = $distribuidora->relaciones()->whereIn('estado', ['vencida', 'parcial', 'en_perdida'])->get();

            return [
                'distribuidora_id' => $distribuidora->id,
                'numero_distribuidora' => $distribuidora->numero_distribuidora,
                'sucursal' => $distribuidora->sucursal?->nombre,
                'estado_distribuidora' => $distribuidora->estado,
                'saldo_pendiente_total' => round((float) $relacionesMorosas->sum(
                    fn ($r) => max(0, (float) $r->total_a_pagar - (float) $r->total_abonado)
                ), 2),
                'relaciones_vencidas' => $distribuidora->relaciones()->where('estado', 'vencida')->count(),
                'relaciones_en_perdida' => $distribuidora->relaciones()->where('estado', 'en_perdida')->count(),
            ];
        });

        return $this->success(
            data: $distribuidoras->values(),
            message: 'Reporte de distribuidoras morosas obtenido exitosamente.'
        );
    }
}
