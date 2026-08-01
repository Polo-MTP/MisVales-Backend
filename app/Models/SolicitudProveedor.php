<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SolicitudProveedor extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_proveedor';

    protected $fillable = [
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
    ];

    protected $casts = [
        'cumple' => 'boolean',
        'fecha_verificacion' => 'datetime',
        'fecha_decision' => 'datetime',
        'limite_credito_asignado' => 'decimal:2',
    ];

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function datosPersonales(): BelongsTo
    {
        return $this->belongsTo(DatosPersonales::class, 'datos_id');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    public function verificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificador_id');
    }

    public function gerente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LogNuevoProveedor::class, 'solicitud_id');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class, 'solicitud_id');
    }
}
