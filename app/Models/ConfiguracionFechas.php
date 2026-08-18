<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sucursal_id',
    'dia_corte',
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
