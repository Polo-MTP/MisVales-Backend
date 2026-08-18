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
    'sucursal_id',
    'estado',
    'cumple',
    'comentario_verificador',
    'fecha_verificacion',
    'coordinador_id',
    'verificador_id',
    'gerente_id',
    'comentario_gerente',
    'decision_gerente',
    'limite_credito_asignado',
    'fecha_decision',
    'razon_social',
    'rfc',
    'datos_familiares',
    'datos_vehiculos',
    'datos_vivienda',
    'referencia_laboral',
])]
final class SolicitudProveedor extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_proveedor';

    protected $casts = [
        'cumple' => 'boolean',
        'fecha_verificacion' => 'datetime',
        'fecha_decision' => 'datetime',
        'limite_credito_asignado' => 'decimal:2',
        'datos_familiares' => 'array',
        'datos_vehiculos' => 'array',
        'datos_vivienda' => 'array',
    ];

    /**
     * Sucursal donde se capturó la solicitud.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    /**
     * Datos personales del solicitante.
     */
    public function datosPersonales(): BelongsTo
    {
        return $this->belongsTo(DatosPersonales::class, 'datos_id');
    }

    /**
     * Usuario coordinador asignado a la solicitud.
     */
    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    /**
     * Usuario que verificó la solicitud.
     */
    public function verificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificador_id');
    }

    /**
     * Usuario gerente que tomó la decisión final sobre la solicitud.
     */
    public function gerente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_id');
    }

    /**
     * Bitácora de cambios sobre esta solicitud.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(LogNuevoProveedor::class, 'solicitud_id');
    }

    /**
     * Evidencias (documentos) adjuntas a esta solicitud.
     */
    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class, 'solicitud_id');
    }
}
