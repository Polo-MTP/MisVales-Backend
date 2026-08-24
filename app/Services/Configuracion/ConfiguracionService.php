<?php

declare(strict_types=1);

namespace App\Services\Configuracion;

use App\Models\Configuracion;
use App\Models\ConfiguracionFechas;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ConfiguracionService
{
    /**
     * Obtiene el valor casteado vigente hoy (o en una fecha histórica específica) para una clave.
     */
    public function obtenerValorVigente(string $clave, ?string $fecha = null): mixed
    {
        $fechaConsulta = $fecha ?? now()->toDateString();

        // whereDate(), no where(): vigente_desde se guarda con hora ("2026-08-24 00:00:00"),
        // pero $fechaConsulta es solo fecha ("2026-08-24"). Comparando esos strings tal cual,
        // "2026-08-24 00:00:00" <= "2026-08-24" da FALSE (el string más largo ordena después),
        // así que un cambio marcado "vigente desde hoy" nunca se activaba el mismo día en que
        // se guardaba -- solo hasta el día siguiente. whereDate() compara solo la parte de fecha.
        /** @var Configuracion|null $config */
        $config = Configuracion::query()
            ->where('clave', $clave)
            ->whereDate('vigente_desde', '<=', $fechaConsulta)
            ->where(function ($q) use ($fechaConsulta): void {
                $q->whereNull('vigente_hasta')
                    ->orWhereDate('vigente_hasta', '>=', $fechaConsulta);
            })
            ->latest('id')
            ->first();

        return $config?->valor_casteado;
    }

    /**
     * Obtiene todas las configuraciones clave-valor vigentes actualmente.
     *
     * @return Collection<int, Configuracion>
     */
    public function obtenerTodasVigentes(): Collection
    {
        return Configuracion::query()
            ->with('modificadoPor')
            ->whereNull('vigente_hasta')
            ->orderBy('clave')
            ->get();
    }

    /**
     * Cambia una regla de configuración usando el patrón de vigencia por fecha (transacción de 2 pasos).
     */
    public function cambiarValor(string $clave, string $valor, string $tipoDato, User $usuario): Configuracion
    {
        Log::debug('ConfiguracionService: Cambiando valor de regla', [
            'clave' => $clave,
            'nuevo_valor' => $valor,
            'tipo_dato' => $tipoDato,
            'usuario_id' => $usuario->id,
        ]);

        return DB::transaction(function () use ($clave, $valor, $tipoDato, $usuario): Configuracion {
            $hoy = now()->toDateString();

            // 1. Cerrar la fila vigente actual (si existe)
            Configuracion::query()
                ->where('clave', $clave)
                ->whereNull('vigente_hasta')
                ->update(['vigente_hasta' => $hoy]);

            // 2. Insertar la nueva fila vigente con fecha desde hoy
            /** @var Configuracion $nuevaConfig */
            $nuevaConfig = Configuracion::query()->create([
                'clave' => $clave,
                'valor' => $valor,
                'tipo_dato' => $tipoDato,
                'vigente_desde' => $hoy,
                'vigente_hasta' => null,
                'modificado_por' => $usuario->id,
            ]);

            Log::debug('ConfiguracionService: Regla actualizada exitosamente', [
                'id' => $nuevaConfig->id,
                'clave' => $clave,
            ]);

            return $nuevaConfig->load('modificadoPor');
        });
    }

    /**
     * Obtiene el historial de versiones pasadas de una regla específica.
     *
     * @return Collection<int, Configuracion>
     */
    public function obtenerHistorialClave(string $clave): Collection
    {
        return Configuracion::query()
            ->with('modificadoPor')
            ->where('clave', $clave)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Obtiene la regla de fechas vigente para una sucursal (con fallback automático a default global).
     */
    public function obtenerFechasVigentes(?int $sucursalId = null, ?string $fecha = null): ConfiguracionFechas
    {
        $fechaConsulta = $fecha ?? now()->toDateString();

        // 1. Intentar regla específica de la sucursal
        if ($sucursalId !== null) {
            // whereDate(), no where(): ver el comentario en obtenerValorVigente() -- mismo
            // problema de comparar un string con hora contra uno de solo fecha.
            /** @var ConfiguracionFechas|null $especifica */
            $especifica = ConfiguracionFechas::query()
                ->with(['sucursal', 'modificadoPor'])
                ->where('sucursal_id', $sucursalId)
                ->whereDate('vigente_desde', '<=', $fechaConsulta)
                ->where(function ($q) use ($fechaConsulta): void {
                    $q->whereNull('vigente_hasta')
                        ->orWhereDate('vigente_hasta', '>=', $fechaConsulta);
                })
                ->latest('id')
                ->first();

            if ($especifica) {
                return $especifica;
            }
        }

        // 2. Fallback a la regla global (sucursal_id IS NULL)
        /** @var ConfiguracionFechas|null $global */
        $global = ConfiguracionFechas::query()
            ->with(['sucursal', 'modificadoPor'])
            ->whereNull('sucursal_id')
            ->whereDate('vigente_desde', '<=', $fechaConsulta)
            ->where(function ($q) use ($fechaConsulta): void {
                $q->whereNull('vigente_hasta')
                    ->orWhereDate('vigente_hasta', '>=', $fechaConsulta);
            })
            ->latest('id')
            ->first();

        if ($global) {
            return $global;
        }

        // 3. Objeto por defecto en memoria si aún no hay seeder ejecutado
        $fallback = new ConfiguracionFechas([
            'sucursal_id' => null,
            'dia_corte' => 15,
            'dia_corte_2' => 31, // 31 se capa al último día del mes -- ver esDiaDeCorte().
            'dia_limite_pago' => 16,
            'dias_pago_anticipado' => 3,
            'vigente_desde' => $fechaConsulta,
            'vigente_hasta' => null,
        ]);

        return $fallback;
    }

    /**
     * Obtiene la lista de todas las reglas de fechas vigentes (global + por sucursales).
     *
     * @return Collection<int, ConfiguracionFechas>
     */
    public function obtenerTodasFechasVigentes(): Collection
    {
        return ConfiguracionFechas::query()
            ->with(['sucursal', 'modificadoPor'])
            ->whereNull('vigente_hasta')
            ->orderBy('sucursal_id')
            ->get();
    }

    /**
     * Cambia la regla de fechas para una sucursal (o global) cerrando la vigencia anterior.
     *
     * $diaCorte y $diaCorte2 son los dos días de corte quincenales -- siempre se guardan
     * juntos, nunca uno sin el otro (ver UpdateConfiguracionFechasRequest).
     */
    public function cambiarFechas(?int $sucursalId, int $diaCorte, int $diaCorte2, int $diaLimitePago, int $diasPagoAnticipado, User $usuario): ConfiguracionFechas
    {
        Log::debug('ConfiguracionService: Cambiando regla de fechas', [
            'sucursal_id' => $sucursalId,
            'dia_corte' => $diaCorte,
            'dia_corte_2' => $diaCorte2,
            'dia_limite_pago' => $diaLimitePago,
            'dias_pago_anticipado' => $diasPagoAnticipado,
            'usuario_id' => $usuario->id,
        ]);

        return DB::transaction(function () use ($sucursalId, $diaCorte, $diaCorte2, $diaLimitePago, $diasPagoAnticipado, $usuario): ConfiguracionFechas {
            $hoy = now()->toDateString();

            // 1. Cerrar la fila vigente actual para esa sucursal (o global)
            $query = ConfiguracionFechas::query()->whereNull('vigente_hasta');
            if ($sucursalId === null) {
                $query->whereNull('sucursal_id');
            } else {
                $query->where('sucursal_id', $sucursalId);
            }
            $query->update(['vigente_hasta' => $hoy]);

            // 2. Insertar nueva fila vigente
            /** @var ConfiguracionFechas $nuevaFecha */
            $nuevaFecha = ConfiguracionFechas::query()->create([
                'sucursal_id' => $sucursalId,
                'dia_corte' => $diaCorte,
                'dia_corte_2' => $diaCorte2,
                'dia_limite_pago' => $diaLimitePago,
                'dias_pago_anticipado' => $diasPagoAnticipado,
                'vigente_desde' => $hoy,
                'vigente_hasta' => null,
                'modificado_por' => $usuario->id,
            ]);

            Log::debug('ConfiguracionService: Regla de fechas actualizada exitosamente', [
                'id' => $nuevaFecha->id,
                'sucursal_id' => $sucursalId,
            ]);

            return $nuevaFecha->load(['sucursal', 'modificadoPor']);
        });
    }

    /**
     * Consulta el historial de cambios de fechas (filtrado opcionalmente por sucursal).
     *
     * @return Collection<int, ConfiguracionFechas>
     */
    public function obtenerHistorialFechas(?int $sucursalId = null): Collection
    {
        $query = ConfiguracionFechas::query()->with(['sucursal', 'modificadoPor']);

        if ($sucursalId !== null) {
            $query->where('sucursal_id', $sucursalId);
        }

        return $query->orderByDesc('vigente_desde')->orderByDesc('id')->get();
    }
}
