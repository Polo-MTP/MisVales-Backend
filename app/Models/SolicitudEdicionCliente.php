<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cliente_id',
    'solicitado_por',
    'sucursal_id',
    'campos_propuestos',
    'motivo',
    'estado',
    'autorizado_por',
    'comentario_autorizacion',
    'fecha_decision',
])]
final class SolicitudEdicionCliente extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_edicion_cliente';

    protected $casts = [
        'campos_propuestos' => 'array',
        'fecha_decision' => 'datetime',
    ];

    /**
     * Cliente cuyos datos se propone editar.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Usuario que propuso la edición.
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /**
     * Usuario que autorizó o rechazó la edición.
     */
    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
