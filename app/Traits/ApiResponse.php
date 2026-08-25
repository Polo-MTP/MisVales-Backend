<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    /**
     * Respuesta JSON de éxito estándar de la API.
     */
    protected function success(
        mixed $data = null,
        string $message = 'Success',
        int $code = Response::HTTP_OK
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Respuesta JSON de éxito con código 201, para recursos recién creados.
     */
    protected function created(
        mixed $data = null,
        string $message = 'Resource created successfully'
    ): JsonResponse {
        return $this->success($data, $message, Response::HTTP_CREATED);
    }

    /**
     * Respuesta JSON 204 sin contenido.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    protected function error(
        string $message = 'Error',
        int $code = Response::HTTP_BAD_REQUEST,
        array $errors = [],
        ?ApiErrorCode $errorCode = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
            // Identificador estable en inglés para que un cliente distinga el TIPO de error
            // sin parsear 'message' (texto en español, puede cambiar de redacción). Si quien
            // llama no especifica uno, se infiere del status HTTP -- ver ApiErrorCode::fromHttpStatus().
            'error_code' => ($errorCode ?? ApiErrorCode::fromHttpStatus($code))->value,
        ];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Respuesta JSON 404.
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, Response::HTTP_NOT_FOUND, errorCode: ApiErrorCode::NOT_FOUND);
    }

    /**
     * Respuesta JSON 401.
     */
    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, Response::HTTP_UNAUTHORIZED, errorCode: ApiErrorCode::UNAUTHENTICATED);
    }

    /**
     * Respuesta JSON 403.
     */
    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->error($message, Response::HTTP_FORBIDDEN, errorCode: ApiErrorCode::FORBIDDEN);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    protected function validationError(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->error($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors, ApiErrorCode::VALIDATION_ERROR);
    }
}
