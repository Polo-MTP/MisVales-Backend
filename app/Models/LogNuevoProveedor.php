<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'solicitud_id',
    'entidad_tipo',
    'entidad_id',
    'campo',
    'valor_anterior',
    'valor_nuevo',
    'modificado_por',
    'fecha_hora',
    'dispositivo',
    'accion',
    'motivo',
])]
final class LogNuevoProveedor extends Model
{
    use HasFactory;

    protected $table = 'log_nuevo_proveedor';

    protected $casts = [
        'fecha_hora' => 'datetime',
    ];

    /**
     * Solicitud de alta de proveedor sobre la que se registró este log.
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudProveedor::class, 'solicitud_id');
    }

    /**
     * Usuario que realizó la acción registrada en el log.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificado_por');
    }
}
