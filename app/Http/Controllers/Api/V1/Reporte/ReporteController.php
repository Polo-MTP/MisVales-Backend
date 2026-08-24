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

        // Dos formas de estar moroso, y el reporte tiene que enseñar las dos: por relaciones
        // vencidas/en pérdida, o porque la distribuidora ya quedó marcada MOROSO (lo que le
        // bloquea pedir vales, ver Distribuidora::montoMaximoDisponible()). Antes solo miraba las
        // relaciones, así que una distribuidora marcada MOROSO cuyas relaciones ya se perdonaron o
        // liquidaron salía del reporte pero seguía bloqueada y con el sello MOROSO en el catálogo
        // -- dos pantallas contestando distinto a "¿quién está moroso?".
        $query = Distribuidora::query()
            ->with('sucursal')
            ->where(fn ($q) => $q
                ->whereHas('relaciones', fn ($r) => $r->whereIn('estado', ['vencida', 'en_perdida']))
                ->orWhere('estado', 'MOROSO'));

        // Gerente de Sucursal y Cajera solo ven morosidad de su propia sucursal; Gerente
        // General/Administrador ven todo. Cajera se había quedado fuera de este switch --
        // caía en el mismo "sin filtro" que Gerente General y veía morosas de cualquier
        // sucursal, no solo la suya.
        if (in_array($usuario->role?->name, ['Gerente de Sucursal', 'Cajera'], true)) {
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
