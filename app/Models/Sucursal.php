<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'nombre',
    'codigo',
    'es_matriz',
    'is_active',
])]
final class Sucursal extends Model
{
    use HasFactory;

    protected $table = 'sucursales';

    protected $casts = [
        'es_matriz' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Usuarios (cajeras, gerentes, etc.) que pertenecen a esta sucursal.
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'sucursal_id');
    }

    /**
     * Solicitudes de alta de proveedor capturadas en esta sucursal.
     */
    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudProveedor::class, 'sucursal_id');
    }

    /**
     * Cortes (relaciones) generados para distribuidoras de esta sucursal.
     */
    public function relaciones(): HasMany
    {
        return $this->hasMany(Relacion::class);
    }

    /**
     * Convenios bancarios registrados para esta sucursal.
     */
    public function conveniosBancarios(): HasMany
    {
        return $this->hasMany(ConvenioBancario::class);
    }
}
