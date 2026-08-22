<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'relacion_id',
    'vale_id',
    'concepto',
    'cliente_id',
    'producto_id',
    'cuota_numero',
    'cuotas_totales',
    'capital',
    'comision',
    'interes',
    'seguro',
    'categoria',
    'recargo',
    'pago',
    'total',
    'estado',
])]
final class RelacionDetalle extends Model
{
    use HasFactory;

    protected $table = 'relacion_detalles';

    protected $casts = [
        'cuota_numero' => 'integer',
        'cuotas_totales' => 'integer',
        'capital' => 'decimal:2',
        'comision' => 'decimal:2',
        'interes' => 'decimal:2',
        'seguro' => 'decimal:2',
        'categoria' => 'decimal:2',
        'recargo' => 'decimal:2',
        'pago' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Corte (Relacion) al que pertenece esta cuota.
     */
    public function relacion(): BelongsTo
    {
        return $this->belongsTo(Relacion::class);
    }

    /**
     * Vale al que corresponde esta cuota.
     */
    public function vale(): BelongsTo
    {
        return $this->belongsTo(Vale::class);
    }

    /**
     * Cliente asociado al vale de esta cuota.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Producto asociado al vale de esta cuota.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
