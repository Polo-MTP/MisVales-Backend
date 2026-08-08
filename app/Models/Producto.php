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

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class);
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}