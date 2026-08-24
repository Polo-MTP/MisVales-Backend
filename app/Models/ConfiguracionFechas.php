<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * dia_corte y dia_corte_2 son los dos días de corte quincenales (primera y segunda quincena)
 * -- siempre van en pareja, nunca uno sin el otro (ver UpdateConfiguracionFechasRequest y
 * RelacionCalculoService::esDiaDeCorte()). Un valor mayor a los días reales del mes se capa
 * al último día calendario (31 = "fin de mes" incluso en meses de 28-30 días).
 */
#[Fillable([
    'sucursal_id',
    'dia_corte',
    'dia_corte_2',
    'dia_limite_pago',
    'dias_pago_anticipado',
    'vigente_desde',
    'vigente_hasta',
    'modificado_por',
])]
final class ConfiguracionFechas extends Model
{
    use HasFactory;

    protected $table = 'configuracion_fechas';

    protected $casts = [
        'dia_corte' => 'integer',
        'dia_corte_2' => 'integer',
        'dia_limite_pago' => 'integer',
        'dias_pago_anticipado' => 'integer',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    /**
     * Sucursal a la que aplica esta configuración de fechas.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    /**
     * Usuario que registró o modificó esta configuración.
     */
    public function modificadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificado_por');
    }
}
