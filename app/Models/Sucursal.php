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

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'sucursal_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudProveedor::class, 'sucursal_id');
    }

    public function relaciones(): HasMany
    {
        return $this->hasMany(Relacion::class);
    }

    public function conveniosBancarios(): HasMany
    {
        return $this->hasMany(ConvenioBancario::class);
    }
}
