<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'abono_conciliacion_id',
    'relacion_id',
    'solicitado_por',
    'sucursal_id',
    'motivo',
    'estado',
    'autorizado_por',
    'comentario_autorizacion',
    'fecha_decision',
])]
final class SolicitudConciliacion extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_conciliacion';

    protected $casts = [
        'fecha_decision' => 'datetime',
    ];

    public function abono(): BelongsTo
    {
        return $this->belongsTo(AbonoConciliacion::class, 'abono_conciliacion_id');
    }

    public function relacion(): BelongsTo
    {
        return $this->belongsTo(Relacion::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
