<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Notificacion\NotificacionResource;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificacionController extends ApiController
{
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Lista las notificaciones visibles para el usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $notificaciones = $this->notificacionService->listar($usuario, $request->all());

        return $this->success(
            data: NotificacionResource::collection($notificaciones)->response()->getData(true),
            message: 'Notificaciones obtenidas exitosamente.'
        );
    }
}
