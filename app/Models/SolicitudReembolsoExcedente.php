<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vale_id',
    'distribuidora_id',
    'sucursal_id',
    'monto',
    'solicitado_por',
    'motivo',
    'estado',
    'autorizado_por',
    'comentario_autorizacion',
    'fecha_decision',
])]
final class SolicitudReembolsoExcedente extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_reembolso_excedente';

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_decision' => 'datetime',
    ];

    public function vale(): BelongsTo
    {
        return $this->belongsTo(Vale::class);
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
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
