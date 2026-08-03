<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'usuario_id',
    'numero_distribuidora',
    'limite_credito',
    'credito_disponible',
    'categoria_id',
    'puntos_acumulados',
    'estado',
])]
final class Distribuidora extends Model
{
    use HasFactory;

    protected $table = 'distribuidoras';

    protected $casts = [
        'limite_credito' => 'decimal:2',
        'credito_disponible' => 'decimal:2',
        'puntos_acumulados' => 'integer',
        'estado' => 'boolean',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function historialClientes(): HasMany
    {
        return $this->hasMany(HistorialClienteDistr::class, 'distribuidor_id');
    }
}
