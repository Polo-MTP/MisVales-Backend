<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Vale;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Vale\SolicitarValeRequest;
use App\Http\Resources\Vale\ValeResource;
use App\Models\User;
use App\Models\Vale;
use App\Services\Vale\ValeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ValeController extends ApiController
{
    public function __construct(
        private readonly ValeService $valeService
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vales = $this->valeService->listar($usuario, $request->all());

        return $this->success(
            data: ValeResource::collection($vales)->response()->getData(true),
            message: 'Lista de vales obtenida exitosamente.'
        );
    }

    public function store(SolicitarValeRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vale = $this->valeService->solicitar($request->validated(), $usuario);

        return $this->created(
            data: new ValeResource($vale),
            message: 'Vale solicitado exitosamente. Queda pendiente de autorización.'
        );
    }

    public function autorizar(Vale $vale, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vale = $this->valeService->autorizar($vale, $usuario);

        return $this->success(
            data: new ValeResource($vale),
            message: 'Vale autorizado exitosamente.'
        );
    }
}
