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
        'estado',        // 'solicitado', 'validado', 'autorizado', 'pagado', 'vencido', 'incidencia'
        'activo',        // la distribuidora la activa/desactiva sin autorización
        'fecha_solicitud',
        'validado_por',
        'fecha_validacion',
        'fecha_autorizacion',
        'numero_transferencia',
        'saldo_excedente',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'quincenas' => 'integer',
        'activo' => 'boolean',
        'fecha_solicitud' => 'datetime',
        'fecha_validacion' => 'datetime',
        'fecha_autorizacion' => 'datetime',
        'ine_verificada' => 'boolean',
        'comprobante_domicilio_verificado' => 'boolean',
        'saldo_excedente' => 'decimal:2',
    ];

    /**
     * Distribuidora que solicitó este vale.
     */
    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    /**
     * Cliente para el cual se solicitó este vale.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Producto del catálogo con el que se generó este vale.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Cuotas (detalles de corte) generadas para este vale.
     */
    public function relacionDetalles(): HasMany
    {
        return $this->hasMany(RelacionDetalle::class);
    }

    /**
     * Cajera que validó los datos del cliente antes de autorizar el vale.
     */
    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por');
    }

    /**
     * Movimientos del saldo a favor de ESTE vale (generado por pagos de más en conciliación,
     * consumido en cuotas futuras del mismo vale, o reembolsado) -- ver ExcedenteConciliacionService.
     */
    public function excedenteMovimientos(): HasMany
    {
        return $this->hasMany(ExcedenteMovimiento::class);
    }

    /**
     * Solicitudes de reembolso del saldo a favor de este vale.
     */
    public function solicitudesReembolsoExcedente(): HasMany
    {
        return $this->hasMany(SolicitudReembolsoExcedente::class);
    }
}
