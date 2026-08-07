<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HistorialEstadoDistribuidora extends Model
{
    use HasFactory;

    protected $table = 'historial_estado_distribuidora';

    protected $fillable = [
        'distribuidora_id',
        'estado_anterior',
        'estado_nuevo',
        'motivo',
        'cambiado_por',   // este campo ya lo tienes, es el ID del usuario
        'fecha',
    ];

    protected $casts = [
        'estado_anterior' => 'string',  // ahora es string porque los estados son ENUM
        'estado_nuevo' => 'string',
        'fecha' => 'datetime',
    ];

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    public function cambiadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cambiado_por');
    }
}
