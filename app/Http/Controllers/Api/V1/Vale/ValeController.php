<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Vale;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Vale\SolicitarValeRequest;
use App\Http\Requests\Api\V1\Vale\ValidarValeRequest;
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

    /**
     * Lista los vales visibles para el usuario según su rol.
     */
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

    /**
     * Registra la solicitud de un vale; queda pendiente de autorización.
     */
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

    /**
     * Valida los datos del cliente en persona — paso previo obligatorio para poder autorizar.
     * Si es el primer vale validado del cliente, exige su CLABE interbancaria para poder
     * transferirle el pago.
     */
    public function validar(Vale $vale, ValidarValeRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vale = $this->valeService->validar(
            $vale,
            $usuario,
            $request->validated('clabe'),
            $request->validated('ine_verificada'),
            $request->validated('comprobante_domicilio_verificado'),
        );

        return $this->success(
            data: new ValeResource($vale),
            message: 'Datos del cliente validados exitosamente.'
        );
    }

    /**
     * Autoriza (paga) un vale ya validado.
     */
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

    /**
     * Desactiva un vale propio mientras siga en estado 'solicitado'.
     */
    public function desactivar(Vale $vale, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vale = $this->valeService->desactivar($vale, $usuario);

        return $this->success(
            data: new ValeResource($vale),
            message: 'Vale desactivado exitosamente.'
        );
    }

    /**
     * Reactiva un vale propio previamente desactivado.
     */
    public function activar(Vale $vale, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vale = $this->valeService->activar($vale, $usuario);

        return $this->success(
            data: new ValeResource($vale),
            message: 'Vale activado exitosamente.'
        );
    }
}
