<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'distribuidora_id',
    'relacion_id',
    'tipo',
    'monto',
    'motivo',
    'registrado_por',
])]
final class ExcedenteMovimiento extends Model
{
    use HasFactory;

    protected $table = 'excedente_movimientos';

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    /**
     * Distribuidora dueña de este movimiento de excedente.
     */
    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    /**
     * Relación (corte) que originó el excedente (tipo=generado) o lo consumió (tipo=aplicado).
     */
    public function relacion(): BelongsTo
    {
        return $this->belongsTo(Relacion::class);
    }

    /**
     * Usuario que registró el movimiento, si fue manual (los automáticos quedan null).
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
