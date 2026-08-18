<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CategoriaDistribuidora extends Model
{
    protected $table = 'categorias_distribuidoras';

    protected $fillable = [
        'nombre',
        'porcentaje_comision',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'porcentaje_comision' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /**
     * Distribuidoras clasificadas en esta categoría.
     */
    public function distribuidoras(): HasMany
    {
        return $this->hasMany(Distribuidora::class, 'categoria_id');
    }
}