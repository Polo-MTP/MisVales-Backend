<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'distribuidora_id',
    'solicitado_por',
    'limite_credito_anterior',
    'monto_solicitado',
    'motivo',
])]
final class SolicitudAumentoCredito extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_aumento_credito';

    protected $casts = [
        'limite_credito_anterior' => 'decimal:2',
        'monto_solicitado' => 'decimal:2',
        'monto_otorgado' => 'decimal:2',
        'fecha_decision' => 'datetime',
    ];

    /**
     * Distribuidora que pide el aumento.
     */
    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    /**
     * Usuario que capturó la solicitud (la propia distribuidora o su coordinador).
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /**
     * Gerente que decidió el monto final otorgado.
     */
    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }
}
