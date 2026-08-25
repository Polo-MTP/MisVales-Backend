<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Configuracion\ConfiguracionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'rfc',
        'usuario_acceso',
        'password_hash',
        'comentarios_verificador',
        'fecha_aprobacion',
        'aprobado_por',
        'contrato_url',
    ];

    protected $casts = [
        'limite_credito' => 'decimal:2',
        'puntos_acumulados' => 'integer',
        'estado' => 'string',
        'fecha_aprobacion' => 'datetime',
    ];

    // ─── Relaciones ─────────────────────────────────────────────

    /**
     * Usuario dueño de la distribuidora (puede ser el coordinador o el representante).
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Sucursal a la que pertenece la distribuidora.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Usuario con rol Coordinador asignado a la distribuidora.
     */
    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    /**
     * Usuario que verificó la solicitud de alta de la distribuidora.
     */
    public function verificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificador_id');
    }

    /**
     * Usuario que aprobó el alta de la distribuidora.
     */
    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /**
     * Categoría de la distribuidora (define su porcentaje de comisión).
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaDistribuidora::class, 'categoria_id');
    }

    /**
     * Datos adicionales del solicitante capturados durante el alta
     * (familiares, vehículo, vivienda, referencia laboral).
     */
    public function datosExtras(): HasOne
    {
        return $this->hasOne(DistribuidorDatosExtras::class);
    }

    /**
     * Historial de cambios de estado de la distribuidora.
     */
    public function historialEstados(): HasMany
    {
        return $this->hasMany(HistorialEstadoDistribuidora::class);
    }

    /**
     * Historial de clientes que han pertenecido a esta distribuidora.
     */
    public function historialClientes(): HasMany
    {
        return $this->hasMany(HistorialClienteDistr::class, 'distribuidor_id');
    }

    /**
     * Vales solicitados por esta distribuidora.
     */
    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class);
    }

    /**
     * Relaciones (cortes/estados de cuenta) generadas para esta distribuidora.
     */
    public function relaciones(): HasMany
    {
        return $this->hasMany(Relacion::class);
    }

    /**
     * Movimientos de puntos de fidelidad de la distribuidora.
     */
    public function puntosMovimientos(): HasMany
    {
        return $this->hasMany(PuntoMovimiento::class);
    }

    /**
     * Movimientos del saldo a favor generado por pagos de más en conciliación bancaria --
     * denormalizado aquí (además de en cada Vale) solo para reporte/auditoría a nivel
     * distribuidora; el saldo real vive por vale (ver getSaldoExcedenteAttribute()).
     */
    public function excedenteMovimientos(): HasMany
    {
        return $this->hasMany(ExcedenteMovimiento::class);
    }

    /**
     * Perdones de recargo/interés otorgados a esta distribuidora.
     */
    public function relacionPerdones(): HasMany
    {
        return $this->hasMany(RelacionPerdon::class);
    }

    /**
     * Solicitudes de aumento de línea de crédito de esta distribuidora.
     */
    public function solicitudesAumentoCredito(): HasMany
    {
        return $this->hasMany(SolicitudAumentoCredito::class);
    }

    /**
     * Es persona física, no persona moral: no existe un "nombre de negocio" propio, guardado
     * aparte -- es su nombre de siempre (datos_personales.nombre + apellidos). Calculado aquí
     * en vez de duplicado en columna para que nunca pueda desincronizarse de una corrección
     * hecha directo sobre datos_personales.
     */
    public function getNombreAttribute(): ?string
    {
        $datos = $this->usuario?->datosPersonales;

        if (! $datos) {
            return null;
        }

        return trim($datos->nombre.' '.$datos->apellido_paterno.' '.($datos->apellido_materno ?? ''));
    }

    /**
     * Suma del saldo a favor de TODOS los vales de esta distribuidora -- solo para mostrar un
     * total en el dashboard/"ver saldo disponible". El saldo real (y el que se consume/
     * reembolsa) vive por vale (Vale::saldo_excedente), a propósito: así el excedente de un
     * cliente nunca paga la deuda de otro cliente de la misma distribuidora.
     */
    public function getSaldoExcedenteAttribute(): float
    {
        return (float) ($this->vales()->sum('saldo_excedente') ?? 0);
    }

    /**
     * Crédito disponible: límite de crédito menos el monto de vales que aún cuentan
     * contra el crédito (mismo criterio de "pendiente" que usa RelacionCalculoService).
     */
    public function getCreditoDisponibleAttribute(): float
    {
        $totalEnUso = $this->vales()
            ->where('activo', true)
            ->whereIn('estado', ['autorizado', 'parcial', 'vencido'])
            ->sum('monto') ?? 0;

        return max(0, (float) $this->limite_credito - (float) $totalEnUso);
    }

    /**
     * Verifica si la distribuidora puede solicitar un vale por el monto indicado.
     * Ver montoMaximoDisponible() para el detalle de las reglas.
     */
    public function puedeSolicitarVale(float $montoSolicitado, bool $esPrimerVale = false): bool
    {
        return $montoSolicitado <= $this->montoMaximoDisponible($esPrimerVale);
    }

    /**
     * Monto máximo que la distribuidora puede pedir ahora mismo en un vale nuevo: debe estar
     * activa/en verificación, respetar el crédito disponible y, si es su primer vale (o el
     * primero desde un aumento de crédito reciente), no exceder el porcentaje máximo
     * configurable ('regla_50_pct'). Sirve tanto para validar una solicitud puntual
     * (puedeSolicitarVale) como para filtrar de antemano qué productos del catálogo puede
     * ver/elegir (ProductoService::listar()).
     */
    public function montoMaximoDisponible(bool $esPrimerVale = false): float
    {
        // Solo pueden solicitar si están activas o en verificación
        if (! in_array($this->estado, ['ACTIVO', 'EN_VERIFICACION'])) {
            return 0.0;
        }

        $configuracionService = app(ConfiguracionService::class);
        $porcentaje = (float) ($configuracionService->obtenerValorVigente('regla_50_pct') ?? 50);

        // Regla del porcentaje máximo para el primer vale de la distribuidora.
        if ($esPrimerVale) {
            return min($this->credito_disponible, $this->limite_credito * ($porcentaje / 100));
        }

        // Un aumento de crédito no se puede usar de un solo golpe: el primer vale después de
        // un aumento aprobado y aún no "estrenado" respeta el mismo porcentaje de caución,
        // mas un margen de tolerancia fijo (config 'margen_aumento_credito', default $500).
        if ($this->aumentoCreditoSinConsumir() !== null) {
            $margen = (float) ($configuracionService->obtenerValorVigente('margen_aumento_credito') ?? 500);

            return min($this->credito_disponible, ($this->credito_disponible * ($porcentaje / 100)) + $margen);
        }

        return $this->credito_disponible;
    }

    /**
     * El aumento de crédito aprobado más reciente, si todavía no se le ha solicitado ningún
     * vale desde que se otorgó (la caución de puedeSolicitarVale() solo aplica una vez, al
     * primer vale que "estrena" ese aumento — igual que la regla del primer vale de siempre).
     */
    public function aumentoCreditoSinConsumir(): ?SolicitudAumentoCredito
    {
        /** @var SolicitudAumentoCredito|null $ultimo */
        $ultimo = $this->solicitudesAumentoCredito()
            ->where('estado', 'aprobada')
            ->latest('fecha_decision')
            ->first();

        if (! $ultimo || ! $ultimo->fecha_decision) {
            return null;
        }

        // Estrictamente posterior (no >=): 'created_at'/'fecha_decision' solo guardan hasta el
        // segundo (sin microsegundos). Con >=, un vale creado ANTES del aumento pero dentro del
        // mismo segundo que fecha_decision se leía como "ya se usó el aumento" y se saltaba por
        // completo el tope del 50% en el vale que en realidad debía llevarlo. Ante la duda entre
        // los dos segundos iguales, es más seguro seguir cobrando la caución un tantito de más
        // que dejarla pasar del todo.
        $yaSeUsoDespuesDelAumento = $this->vales()
            ->where('created_at', '>', $ultimo->fecha_decision)
            ->exists();

        return $yaSeUsoDespuesDelAumento ? null : $ultimo;
    }
}
