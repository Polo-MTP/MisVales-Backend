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
    'cantidad',
    'valor_punto_snapshot',
    'motivo',
    'registrado_por',
])]
final class PuntoMovimiento extends Model
{
    use HasFactory;

    protected $table = 'puntos_movimientos';

    protected $casts = [
        'cantidad' => 'integer',
        'valor_punto_snapshot' => 'decimal:2',
    ];

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    public function relacion(): BelongsTo
    {
        return $this->belongsTo(Relacion::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
