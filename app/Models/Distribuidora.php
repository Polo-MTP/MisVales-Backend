<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Services\Configuracion\ConfiguracionService;

final class Distribuidora extends Model
{
    use HasFactory;

    protected $table = 'distribuidoras';

    protected $fillable = [
        'usuario_id',          // ← lo conservamos porque ya lo tienes
        'numero_distribuidora',
        'limite_credito',
        // 'credito_disponible' → lo calculamos, no lo guardamos
        'categoria_id',
        'puntos_acumulados',
        'estado',              // ahora es string (ACTIVO, INACTIVO, etc.)
        // Nuevos campos que agregaste en tu migración:
        'sucursal_id',
        'coordinador_id',
        'verificador_id',
        'razon_social',
        'rfc',
        'usuario_acceso',
        'password_hash',
        'comentarios_verificador',
        'fecha_aprobacion',
        'aprobado_por',
    ];

    protected $casts = [
        'limite_credito' => 'decimal:2',
        'puntos_acumulados' => 'integer',
        'estado' => 'string',
        'fecha_aprobacion' => 'datetime',
    ];

    // ─── Relaciones ─────────────────────────────────────────────

    // La relación con User (ahora es el usuario dueño de la distribuidora,
    // pero puede ser el coordinador o el representante)
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    public function verificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificador_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaDistribuidora::class, 'categoria_id');
    }

    // Datos adicionales del solicitante capturados durante el alta (familiares, vehículo, vivienda, referencia laboral)
    public function datosExtras(): HasOne
    {
        return $this->hasOne(DistribuidorDatosExtras::class);
    }

    // Historial de estados (tabla historial_estado_distribuidora)
    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstadoDistribuidora::class);
    }

    // Historial de clientes (la que ya tenías)
    public function historialClientes(): HasMany
    {
        return $this->hasMany(HistorialClienteDistr::class, 'distribuidor_id');
    }

    // Vales solicitados por esta distribuidora
    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class);
    }

    // Relaciones (cortes/estados de cuenta) generadas para esta distribuidora
    public function relaciones(): HasMany
    {
        return $this->hasMany(Relacion::class);
    }

    public function puntosMovimientos(): HasMany
    {
        return $this->hasMany(PuntoMovimiento::class);
    }

    public function relacionPerdones(): HasMany
    {
        return $this->hasMany(RelacionPerdon::class);
    }

    // ─── Accesor (crédito disponible calculado) ──────────────

    public function getCreditoDisponibleAttribute(): float
    {
        $totalEnUso = $this->vales()
            ->whereIn('estado', ['activo', 'parcial'])
            ->sum('monto') ?? 0;

        return max(0, (float) $this->limite_credito - (float) $totalEnUso);
    }

    // ─── Métodos de negocio ────────────────────────────────────

    public function puedeSolicitarVale(float $montoSolicitado, bool $esPrimerVale = false): bool
    {
        // Solo pueden solicitar si están activas o en verificación
        if (! in_array($this->estado, ['ACTIVO', 'EN_VERIFICACION'])) {
            return false;
        }

        if ($montoSolicitado > $this->credito_disponible) {
            return false;
        }

        // Regla del porcentaje máximo para el primer vale (configurable, clave 'regla_50_pct')
        if ($esPrimerVale) {
            $porcentaje = (float) (app(ConfiguracionService::class)->obtenerValorVigente('regla_50_pct') ?? 50);

            if ($montoSolicitado > $this->limite_credito * ($porcentaje / 100)) {
                return false;
            }
        }

        return true;
    }
}
