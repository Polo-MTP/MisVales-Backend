<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'relacion_id',
    'referencia_leida',
    'monto',
    'folio_pago',
    'fecha_pago',
    'hora_pago',
    'tipo_pago',
    'convenio_bancario_id',
    'estado',
    'conciliado_por',
    'autorizado_por',
    'motivo_manual',
    'lote_archivo',
    'subido_por',
])]
final class AbonoConciliacion extends Model
{
    use HasFactory;

    protected $table = 'abonos_conciliacion';

    /**
     * queja_* queda fuera de Fillable a propósito: solo las escribe
     * ConciliacionBancariaService::levantarQueja(), nunca mass-assignment de un request.
     */
    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_pago' => 'date',
        'queja_fecha' => 'datetime',
    ];

    /**
     * Relación (corte) a la que pertenece este abono.
     */
    public function relacion(): BelongsTo
    {
        return $this->belongsTo(Relacion::class);
    }

    /**
     * Convenio bancario por el que se recibió el pago.
     */
    public function convenioBancario(): BelongsTo
    {
        return $this->belongsTo(ConvenioBancario::class);
    }

    /**
     * Usuario que concilió manualmente el abono.
     */
    public function conciliadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conciliado_por');
    }

    /**
     * Usuario que autorizó la conciliación manual.
     */
    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    /**
     * Usuario que subió el lote de conciliación.
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /**
     * Usuario (distribuidora) que levantó la queja sobre este abono.
     */
    public function quejaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queja_por');
    }
}
