<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'distribuidor_id',
    'coordinador_id',
    'fecha_inicio',
    'fecha_fin',
    'asignado_por',
    'motivo',
])]
final class HistorialCoordinador extends Model
{
    use HasFactory;

    protected $table = 'historial_coordinador';

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
    ];

    public function distribuidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distribuidor_id');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    public function asignador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }
}
