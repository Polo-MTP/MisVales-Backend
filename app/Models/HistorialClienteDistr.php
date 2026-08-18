<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'distribuidor_id',
    'cliente_id',
    'fecha_inicio',
    'fecha_fin',
])]
final class HistorialClienteDistr extends Model
{
    use HasFactory;

    protected $table = 'historial_cliente_distr';

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
    ];

    /**
     * Distribuidora a la que estuvo/está asignado el cliente en este periodo.
     */
    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidor_id');
    }

    /**
     * Cliente al que corresponde este periodo de historial.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
