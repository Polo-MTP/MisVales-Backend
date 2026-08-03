<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'distribuidora_id',
    'estado_anterior',
    'estado_nuevo',
    'motivo',
    'cambiado_por',
    'fecha',
])]
final class HistorialEstadoDistribuidora extends Model
{
    use HasFactory;

    protected $table = 'historial_estado_distribuidora';

    protected $casts = [
        'estado_anterior' => 'boolean',
        'estado_nuevo' => 'boolean',
        'fecha' => 'datetime',
    ];

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function cambiadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cambiado_por');
    }
}
