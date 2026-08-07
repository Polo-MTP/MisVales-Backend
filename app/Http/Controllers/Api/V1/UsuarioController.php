<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UsuarioController extends ApiController
{
    /**
     * Lista usuarios activos, opcionalmente filtrados por rol (?filter[role]=Verificador).
     * Igual que en alta-proveedor: Gerente General y Administrador ven todas las sucursales,
     * el resto de roles solo ve usuarios de su propia sucursal.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $query = User::query()->where('is_active', true);

        if ($authUser->role?->name !== 'Gerente General' && $authUser->role?->name !== 'Administrador') {
            $query->where('sucursal_id', $authUser->sucursal_id);
        }

        $roleFiltro = $request->input('filter.role');
        if ($roleFiltro) {
            $query->whereHas('role', fn ($q) => $q->where('name', $roleFiltro));
        }

        $usuarios = $query->with(['role', 'sucursal'])->orderBy('name')->get();

        return $this->success(UserResource::collection($usuarios));
    }
}
