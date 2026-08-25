<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Notificacion\NotificacionResource;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use DomainException;
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

    /**
     * Marca una notificación como leída — solo su propio destinatario puede hacerlo.
     */
    public function marcarLeida(Request $request, Notificacion $notificacion): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $notificacion = $this->notificacionService->marcarLeida($notificacion, $usuario);

            return $this->success(new NotificacionResource($notificacion), 'Notificación marcada como leída.');
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422, [], ApiErrorCode::DOMAIN_ERROR);
        }
    }
}
