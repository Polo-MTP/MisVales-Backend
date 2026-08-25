<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Relacion;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Relacion\DecidirReembolsoExcedenteRequest;
use App\Http\Requests\Api\V1\Relacion\SolicitarReembolsoExcedenteRequest;
use App\Http\Resources\Relacion\SolicitudReembolsoExcedenteResource;
use App\Models\SolicitudReembolsoExcedente;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\SolicitudReembolsoExcedenteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SolicitudReembolsoExcedenteController extends ApiController
{
    public function __construct(
        private readonly SolicitudReembolsoExcedenteService $service
    ) {}

    /**
     * Lista solicitudes de reembolso de excedente visibles para el usuario según su rol.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitudes = $this->service->listar($usuario, $request->all());

        return $this->success(
            data: SolicitudReembolsoExcedenteResource::collection($solicitudes)->response()->getData(true),
            message: 'Lista de solicitudes de reembolso de excedente obtenida exitosamente.'
        );
    }

    /**
     * La cajera solicita el reembolso del saldo a favor de un vale ya liquidado que no
     * quede pendiente de ninguna cuota futura que lo consuma solo.
     */
    public function store(SolicitarReembolsoExcedenteRequest $request, Vale $vale): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitud = $this->service->solicitar(
            $vale,
            $usuario,
            $request->filled('motivo') ? (string) $request->string('motivo') : null
        );

        return $this->created(
            data: new SolicitudReembolsoExcedenteResource($solicitud),
            message: 'Solicitud de reembolso enviada. Queda pendiente de autorización del gerente.'
        );
    }

    /**
     * El Gerente aprueba o rechaza la solicitud. Al aprobar, el saldo a favor del vale queda en
     * cero -- el dinero real se transfiere fuera del sistema, esto solo deja constancia.
     */
    public function decidir(DecidirReembolsoExcedenteRequest $request, SolicitudReembolsoExcedente $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->service->decidir(
                $solicitud,
                (string) $request->string('decision'),
                $request->filled('comentario') ? (string) $request->string('comentario') : null,
                $usuario
            );

            return $this->success(
                data: new SolicitudReembolsoExcedenteResource($solicitud),
                message: 'Decisión registrada exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422, [], ApiErrorCode::DOMAIN_ERROR);
        }
    }
}
