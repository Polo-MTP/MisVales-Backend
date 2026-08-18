<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    protected $fillable = [
        'monto',
        'quincenas',
        'variante',
        'descripcion',
        'activo',
        'created_by',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'quincenas' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * Usuario que dio de alta este producto.
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Vales generados a partir de este producto.
     */
    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class);
    }

    /**
     * Limita la consulta a productos activos (catálogo disponible para solicitar vales).
     */
    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}