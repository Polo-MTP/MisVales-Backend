<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cliente_id',
    'distribuidora_origen_id',
    'distribuidora_destino_id',
    'solicitado_por',
    'motivo',
    'estado',
    'autorizado_por',
    'comentario_autorizacion',
    'fecha_autorizacion',
    'fecha_aceptacion',
])]
final class SolicitudTransferenciaCliente extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_transferencia_cliente';

    protected $casts = [
        'fecha_autorizacion' => 'datetime',
        'fecha_aceptacion' => 'datetime',
    ];

    /**
     * Cliente cuya transferencia se solicita.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Distribuidora a la que pertenece el cliente actualmente.
     */
    public function distribuidoraOrigen(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_origen_id');
    }

    /**
     * Distribuidora que quiere recibir al cliente.
     */
    public function distribuidoraDestino(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_destino_id');
    }

    /**
     * Usuario (de la distribuidora destino) que capturó la solicitud.
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /**
     * Coordinador (del cliente/distribuidora origen) o Gerente que autorizó/rechazó.
     */
    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
