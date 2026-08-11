<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vale extends Model
{
    use HasFactory;

    protected $table = 'vales';

    protected $fillable = [
        'distribuidora_id',
        'cliente_id',
        'producto_id',
        'monto',
        'quincenas',     // snapshot del producto al momento del alta
        'tipo',          // 'pre-vale' o 'vale-digital'
        'estado',        // 'solicitado', 'autorizado', 'pagado', 'vencido', 'incidencia'
        'activo',        // la distribuidora la activa/desactiva sin autorización
        'fecha_solicitud',
        'fecha_autorizacion',
        'numero_transferencia',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'quincenas' => 'integer',
        'activo' => 'boolean',
        'fecha_solicitud' => 'datetime',
        'fecha_autorizacion' => 'datetime',
    ];

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class); // asumiendo que tienes modelo Cliente
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function relacionDetalles(): HasMany
    {
        return $this->hasMany(RelacionDetalle::class);
    }
}
