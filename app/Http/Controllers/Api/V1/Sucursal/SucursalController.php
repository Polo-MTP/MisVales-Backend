<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sucursal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Sucursal\SucursalStoreRequest;
use App\Http\Requests\Api\V1\Sucursal\SucursalUpdateRequest;
use App\Http\Resources\Sucursal\SucursalResource;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SucursalController extends ApiController
{
    /**
     * Lista sucursales. Por defecto solo las activas; ?activas=false también incluye las
     * desactivadas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Sucursal::query()->orderBy('nombre');

        if ($request->boolean('activas', true)) {
            $query->where('is_active', true);
        }

        return $this->success(SucursalResource::collection($query->get()));
    }

    /**
     * Da de alta una nueva sucursal.
     */
    public function store(SucursalStoreRequest $request): JsonResponse
    {
        $sucursal = Sucursal::query()->create($request->validated());

        return $this->created(new SucursalResource($sucursal));
    }

    /**
     * Devuelve el detalle de una sucursal.
     */
    public function show(Sucursal $sucursal): JsonResponse
    {
        return $this->success(new SucursalResource($sucursal));
    }

    /**
     * Actualiza los datos de una sucursal existente. Si se desactiva, desactiva en cascada al
     * personal que trabaja ahí (Gerente de Sucursal, Coordinador, Verificador, Cajera) -- sin
     * esto, cerrar una sucursal no le quitaba el acceso a nadie que ya trabajara en ella, solo
     * bloqueaba altas nuevas (ver UsuarioController::crearPersonalSucursal()). El bloqueo real
     * lo aplica EnsureUserIsActive en su siguiente petición, igual que cualquier otra baja.
     */
    public function update(SucursalUpdateRequest $request, Sucursal $sucursal): JsonResponse
    {
        $datos = $request->validated();
        $seDesactiva = $sucursal->is_active && array_key_exists('is_active', $datos) && ! $datos['is_active'];

        $sucursal->update($datos);

        if ($seDesactiva) {
            User::query()
                ->where('sucursal_id', $sucursal->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        return $this->success(new SucursalResource($sucursal));
    }
}
