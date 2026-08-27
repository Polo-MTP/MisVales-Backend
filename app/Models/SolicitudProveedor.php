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
    'rfc',
    'datos_familiares',
    'datos_vehiculos',
    'datos_vivienda',
    'referencia_laboral',
    'categoria_id',
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
     * Es persona física, no persona moral: no existe un "nombre de negocio" propio, guardado
     * aparte -- es su nombre de siempre. Calculado aquí en vez de duplicado en columna para
     * que nunca pueda desincronizarse de una corrección hecha directo sobre datos_personales.
     */
    public function getNombreAttribute(): ?string
    {
        $datos = $this->datosPersonales;

        if (! $datos) {
            return null;
        }

        return trim($datos->nombre.' '.$datos->apellido_paterno.' '.($datos->apellido_materno ?? ''));
    }

    /**
     * Categoría (Bronce, Plata, Oro, etc.) elegida por el Coordinador al capturar la
     * solicitud -- se traslada a la Distribuidora cuando Gerencia la aprueba (ver
     * SolicitudProveedorService::aprobarORechazar()). Opcional: puede asignarse o
     * cambiarse después directamente sobre la Distribuidora ya creada.
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaDistribuidora::class, 'categoria_id');
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
