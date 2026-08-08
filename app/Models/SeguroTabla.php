<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'monto_desde',
    'monto_hasta',
    'seguro_monto',
    'activo',
])]
final class SeguroTabla extends Model
{
    use HasFactory;

    protected $table = 'seguros_tabla';

    protected $casts = [
        'monto_desde' => 'decimal:2',
        'monto_hasta' => 'decimal:2',
        'seguro_monto' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }

    public function scopeParaMonto($query, float $monto)
    {
        return $query->where('monto_desde', '<=', $monto)
            ->where(function ($q) use ($monto): void {
                $q->whereNull('monto_hasta')->orWhere('monto_hasta', '>=', $monto);
            });
    }
}
