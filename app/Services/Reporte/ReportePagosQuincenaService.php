<?php

declare(strict_types=1);

namespace App\Services\Reporte;

use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Exporta, para una distribuidora, el desglose de pagos por quincena (cuota) de todos sus
 * vales hasta el corte que elija el Gerente General ("hasta esa quincena"). Muestra cada
 * quincena por separado -- incluidas las 'arrastrada' (una que se quedó sin pagar y cuyo saldo
 * ya se movió a la siguiente, ver RelacionCalculoService::calcularDetalleVale()) -- para que se
 * vea la historia completa del vale, no solo el monto acumulado vigente.
 */
final class ReportePagosQuincenaService
{
    /**
     * Cuotas de la distribuidora hasta (inclusive) el corte indicado, ordenadas por cliente y
     * fecha de corte. Se omiten las cuotas ya liquidadas ('pagado') salvo que sean la primera o
     * la última del vale -- dan contexto de inicio/cierre sin llenar el archivo de cuotas ya
     * resueltas que no aportan nada a un reporte pensado para ver pendientes/atrasos. Las
     * 'arrastrada' SÍ se incluyen siempre -- quieren ver cada quincena por separado, no solo la
     * acumulada (ver generarExcel(), que las excluye del total sumado del pie para no duplicar
     * esa deuda, ya que vive dentro del total de la cuota que la absorbió).
     *
     * @return Collection<int, RelacionDetalle>
     */
    public function obtenerDetalles(Distribuidora $distribuidora, Relacion $hastaRelacion): Collection
    {
        return RelacionDetalle::query()
            ->whereHas('relacion', fn ($q) => $q
                ->where('distribuidora_id', $distribuidora->id)
                ->whereDate('fecha_corte', '<=', $hastaRelacion->fecha_corte))
            ->where(fn ($q) => $q
                ->where('estado', '!=', 'pagado')
                ->orWhere('cuota_numero', 1)
                ->orWhereColumn('cuota_numero', '=', 'cuotas_totales'))
            ->with(['relacion', 'cliente.datosPersonales', 'producto'])
            ->get()
            ->sortBy([
                fn (RelacionDetalle $d) => $d->cliente?->datosPersonales?->nombre ?? '',
                fn (RelacionDetalle $d) => $d->relacion?->fecha_corte,
            ])
            ->values();
    }

    public function generarExcel(Distribuidora $distribuidora, Relacion $hastaRelacion): Spreadsheet
    {
        $detalles = $this->obtenerDetalles($distribuidora, $hastaRelacion);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pagos por quincena');

        $sheet->fromArray(['Concepto', 'Cliente', 'Producto', 'Cuota', 'Pago', 'Comisión', 'Recargo', 'Total'], null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $fila = 2;
        $totales = ['pago' => 0.0, 'comision' => 0.0, 'recargo' => 0.0, 'total' => 0.0];

        foreach ($detalles as $detalle) {
            $nombreCliente = trim(($detalle->cliente?->datosPersonales?->nombre ?? '').' '.($detalle->cliente?->datosPersonales?->apellido_paterno ?? ''));
            $producto = $detalle->producto?->descripcion ?? "Vale #{$detalle->vale_id}";

            $sheet->fromArray([
                // 'arrastrada': ya no es una referencia de pago válida, no se le da concepto.
                $detalle->estado === 'arrastrada' ? '' : $detalle->concepto,
                $nombreCliente,
                $producto,
                "{$detalle->cuota_numero}/{$detalle->cuotas_totales}",
                (float) $detalle->pago,
                (float) $detalle->comision,
                (float) $detalle->recargo,
                (float) $detalle->total,
            ], null, "A{$fila}");

            // 'arrastrada': gris, para distinguirla de un atraso normal -- su 'total' ya viene
            // arrastrado dentro de la cuota siguiente del mismo vale, así que NO entra a la suma
            // del pie (evita duplicar esa deuda), pero se deja visible en el archivo como el
            // monto real que llegó a deber esa quincena.
            if ($detalle->estado === 'arrastrada') {
                $sheet->getStyle("A{$fila}:H{$fila}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E2E3E5');
            } elseif ((float) $detalle->recargo > 0.0) {
                // Resalta la cuota que se atrasó -- misma señal visual que "Pago atrasado" en pantalla.
                $sheet->getStyle("A{$fila}:H{$fila}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFF3CD');
            }

            $totales['pago'] += (float) $detalle->pago;
            $totales['comision'] += (float) $detalle->comision;
            $totales['recargo'] += (float) $detalle->recargo;
            if ($detalle->estado !== 'arrastrada') {
                $totales['total'] += (float) $detalle->total;
            }

            $fila++;
        }

        if ($fila > 2) {
            $sheet->getStyle('E2:H'.($fila - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }

        $filaTotales = $fila + 1;
        $sheet->setCellValue("D{$filaTotales}", 'TOTALES');
        $sheet->setCellValue("E{$filaTotales}", $totales['pago']);
        $sheet->setCellValue("F{$filaTotales}", $totales['comision']);
        $sheet->setCellValue("G{$filaTotales}", $totales['recargo']);
        $sheet->setCellValue("H{$filaTotales}", $totales['total']);
        $sheet->getStyle("D{$filaTotales}:H{$filaTotales}")->getFont()->setBold(true);
        $sheet->getStyle("E{$filaTotales}:H{$filaTotales}")->getNumberFormat()->setFormatCode('"$"#,##0.00');

        foreach (range('A', 'H') as $columna) {
            $sheet->getColumnDimension($columna)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
