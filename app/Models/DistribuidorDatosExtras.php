<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DistribuidorDatosExtras extends Model
{
    protected $table = 'distribuidor_datos_extras';

    protected $fillable = [
        'distribuidora_id',
        'datos_familiares',
        'datos_vehiculos',
        'datos_vivienda',
        'referencia_laboral',
    ];

    protected $casts = [
        'datos_familiares' => 'array',
        'datos_vehiculos' => 'array',
        'datos_vivienda' => 'array',
    ];

    /**
     * Distribuidora dueña de estos datos extra.
     */
    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }
}