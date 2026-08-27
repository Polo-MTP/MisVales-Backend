<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporte;

use App\Http\Controllers\Api\ApiController;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\User;
use App\Services\Reporte\ReportePagosQuincenaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Descarga en Excel el desglose de pagos por quincena de una distribuidora, hasta el corte
     * que se indique (?hasta_relacion_id=; si se omite, usa el corte más reciente de esa
     * distribuidora). Ver ReportePagosQuincenaService para el criterio de qué cuotas incluye.
     */
    public function pagosQuincena(Request $request, ReportePagosQuincenaService $service): StreamedResponse
    {
        $request->validate([
            'distribuidora_id' => ['required', 'integer', 'exists:distribuidoras,id'],
            'hasta_relacion_id' => ['nullable', 'integer'],
        ]);

        /** @var Distribuidora $distribuidora */
        $distribuidora = Distribuidora::query()->findOrFail($request->integer('distribuidora_id'));

        if ($request->filled('hasta_relacion_id')) {
            $hastaRelacion = Relacion::query()
                ->where('distribuidora_id', $distribuidora->id)
                ->findOrFail($request->integer('hasta_relacion_id'));
        } else {
            $hastaRelacion = Relacion::query()
                ->where('distribuidora_id', $distribuidora->id)
                ->latest('fecha_corte')
                ->first();

            if (! $hastaRelacion) {
                abort(404, 'Esta distribuidora todavía no tiene ningún corte generado.');
            }
        }

        $spreadsheet = $service->generarExcel($distribuidora, $hastaRelacion);
        $nombreArchivo = 'pagos-'.$distribuidora->numero_distribuidora.'-hasta-'.$hastaRelacion->fecha_corte->toDateString().'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
