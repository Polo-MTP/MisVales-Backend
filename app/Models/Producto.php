<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Producto extends Model
{
    use HasFactory;

    protected $fillable = [
        'monto',
        'descripcion',
        'activo',
        'created_by',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}