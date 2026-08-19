<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'datos_id',
    'estado',
])]
final class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    /**
     * clabe queda fuera de Fillable a propósito: solo la escribe ValeService::validar() la
     * primera vez que la cajera valida un vale del cliente, nunca debe venir de mass-assignment
     * de un request. Se guarda cifrada (mismo criterio que MfaMethod.secret) por ser un dato
     * bancario sensible.
     */
    protected $casts = [
        'estado' => 'boolean',
        'clabe' => 'encrypted',
    ];

    /**
     * Datos personales del cliente.
     */
    public function datosPersonales(): BelongsTo
    {
        return $this->belongsTo(DatosPersonales::class, 'datos_id');
    }

    /**
     * Historial de distribuidoras a las que ha pertenecido el cliente.
     */
    public function historialDistribuidoras(): HasMany
    {
        return $this->hasMany(HistorialClienteDistr::class, 'cliente_id');
    }
}
