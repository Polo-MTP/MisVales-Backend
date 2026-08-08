<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'distribuidora_id',
    'sucursal_id',
    'referencia_pago',
    'fecha_corte',
    'fecha_limite_pago',
    'fecha_pago_anticipado_desde',
    'fecha_pago_anticipado_hasta',
    'limite_credito_snapshot',
    'categoria_id_snapshot',
    'porcentaje_comision_snapshot',
    'total_capital',
    'total_comision',
    'total_interes',
    'total_seguro',
    'total_recargos',
    'total_a_pagar',
    'total_abonado',
    'puntos_generados',
    'estado',
    'generada_por',
])]
final class Relacion extends Model
{
    use HasFactory;

    protected $table = 'relaciones';

    protected $casts = [
        'fecha_corte' => 'date',
        'fecha_limite_pago' => 'date',
        'fecha_pago_anticipado_desde' => 'date',
        'fecha_pago_anticipado_hasta' => 'date',
        'limite_credito_snapshot' => 'decimal:2',
        'porcentaje_comision_snapshot' => 'decimal:2',
        'total_capital' => 'decimal:2',
        'total_comision' => 'decimal:2',
        'total_interes' => 'decimal:2',
        'total_seguro' => 'decimal:2',
        'total_recargos' => 'decimal:2',
        'total_a_pagar' => 'decimal:2',
        'total_abonado' => 'decimal:2',
        'puntos_generados' => 'integer',
    ];

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function categoriaSnapshot(): BelongsTo
    {
        return $this->belongsTo(CategoriaDistribuidora::class, 'categoria_id_snapshot');
    }

    public function generadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generada_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RelacionDetalle::class);
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(AbonoConciliacion::class);
    }

    public function puntosMovimientos(): HasMany
    {
        return $this->hasMany(PuntoMovimiento::class);
    }

    public function perdon(): HasOne
    {
        return $this->hasOne(RelacionPerdon::class);
    }

    public function getSaldoPendienteAttribute(): float
    {
        return max(0, (float) $this->total_a_pagar - (float) $this->total_abonado);
    }
}
