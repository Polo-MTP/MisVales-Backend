<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Configuracion;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Configuracion\UpdateConfiguracionFechasRequest;
use App\Http\Requests\Api\V1\Configuracion\UpdateConfiguracionRequest;
use App\Http\Resources\Configuracion\ConfiguracionFechasResource;
use App\Http\Resources\Configuracion\ConfiguracionResource;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfiguracionController extends ApiController
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService
    ) {}

    /**
     * Lista todas las reglas clave-valor vigentes hoy.
     */
    public function index(): JsonResponse
    {
        $configs = $this->configuracionService->obtenerTodasVigentes();

        return $this->success(
            data: ConfiguracionResource::collection($configs),
            message: 'Configuraciones vigentes obtenidas exitosamente.'
        );
    }

    /**
     * Cambia una regla clave-valor (cierra la versión anterior e inserta un nuevo rango de vigencia).
     */
    public function store(UpdateConfiguracionRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $clave = (string) $request->input('clave');
        $valor = (string) $request->input('valor');
        $tipoDato = (string) $request->input('tipo_dato');

        $nuevaConfig = $this->configuracionService->cambiarValor($clave, $valor, $tipoDato, $usuario);

        return $this->created(
            data: new ConfiguracionResource($nuevaConfig),
            message: 'Configuración actualizada exitosamente con nuevo rango de vigencia.'
        );
    }

    /**
     * Consulta el historial de versiones pasadas de una regla clave-valor.
     */
    public function historial(string $clave): JsonResponse
    {
        $historial = $this->configuracionService->obtenerHistorialClave($clave);

        return $this->success(
            data: ConfiguracionResource::collection($historial),
            message: "Historial de versiones para '{$clave}' obtenido exitosamente."
        );
    }

    /**
     * Consulta las reglas de fechas vigentes (todas o por sucursal específica con fallback global).
     */
    public function fechasIndex(Request $request): JsonResponse
    {
        $sucursalId = $request->has('sucursal_id') ? (int) $request->input('sucursal_id') : null;

        if ($request->has('sucursal_id')) {
            $fechaVigente = $this->configuracionService->obtenerFechasVigentes($sucursalId);

            return $this->success(
                data: new ConfiguracionFechasResource($fechaVigente),
                message: 'Configuración de fechas obtenida exitosamente.'
            );
        }

        $fechas = $this->configuracionService->obtenerTodasFechasVigentes();

        return $this->success(
            data: ConfiguracionFechasResource::collection($fechas),
            message: 'Configuraciones de fechas vigentes obtenidas exitosamente.'
        );
    }

    /**
     * Cambia la regla de fechas para una sucursal o global (cierra la versión anterior e inserta nueva vigencia).
     */
    public function fechasStore(UpdateConfiguracionFechasRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $sucursalId = $request->input('sucursal_id') !== null ? (int) $request->input('sucursal_id') : null;
        $diaCorte = (int) $request->input('dia_corte');
        $diaLimitePago = (int) $request->input('dia_limite_pago');
        $diasPagoAnticipado = (int) $request->input('dias_pago_anticipado');

        $nuevaFecha = $this->configuracionService->cambiarFechas(
            $sucursalId,
            $diaCorte,
            $diaLimitePago,
            $diasPagoAnticipado,
            $usuario
        );

        return $this->created(
            data: new ConfiguracionFechasResource($nuevaFecha),
            message: 'Configuración de fechas actualizada exitosamente con nuevo rango de vigencia.'
        );
    }

    /**
     * Consulta el historial de cambios de fechas (opcionalmente filtrado por sucursal).
     */
    public function fechasHistorial(Request $request): JsonResponse
    {
        $sucursalId = $request->has('sucursal_id') ? (int) $request->input('sucursal_id') : null;

        $historial = $this->configuracionService->obtenerHistorialFechas($sucursalId);

        return $this->success(
            data: ConfiguracionFechasResource::collection($historial),
            message: 'Historial de configuración de fechas obtenido exitosamente.'
        );
    }
}
