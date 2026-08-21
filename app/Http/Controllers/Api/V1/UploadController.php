<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Storage\SpacesStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

final class UploadController extends ApiController
{
    /**
     * Tipos de archivo que de verdad se usan en el aplicativo (fotos de evidencias/INE,
     * comprobantes, contratos escaneados). Cualquier otro (ej. text/html, image/svg+xml,
     * application/javascript) queda fuera para no poder alojar contenido arbitrario bajo
     * el dominio de confianza del bucket.
     */
    private const CONTENT_TYPES_PERMITIDOS = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        private readonly SpacesStorageService $spacesService
    ) {}

    /**
     * Genera una URL prefirmada (presigned URL) para subir archivos directamente a DigitalOcean Spaces (S3).
     */
    public function getPresignedUrl(Request $request): JsonResponse
    {
        $request->validate([
            'file_name' => 'required|string|max:255',
            'content_type' => ['required', 'string', Rule::in(self::CONTENT_TYPES_PERMITIDOS)],
            // Solo minúsculas/números/guiones -- nada de '/' o '..': el folder se concatena
            // directo en la Key de S3.
            'folder' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/'],
        ]);

        try {
            $data = $this->spacesService->generatePresignedUploadUrl(
                originalFileName: $request->input('file_name'),
                contentType: $request->input('content_type'),
                folder: $request->input('folder', 'uploads')
            );

            return $this->success($data, 'URL prefirmada generada con éxito');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Genera una URL prefirmada temporal para LEER un archivo privado del Space (el 'path'
     * o el 'public_url' que se guardó al subirlo). Cualquier usuario autenticado puede
     * pedirla para cualquier archivo -- la protección real es que la Key de S3 no es
     * adivinable (nombre único con parte aleatoria); no hay verificación de "este usuario
     * puede ver este documento en particular" a este nivel, eso lo sigue filtrando cada
     * endpoint de negocio (Evidencia, contrato, etc.) al decidir qué url_archivo expone.
     */
    public function getPresignedReadUrl(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string|max:500',
        ]);

        try {
            $readUrl = $this->spacesService->generatePresignedReadUrl($request->input('path'));

            return $this->success(['read_url' => $readUrl], 'URL de lectura generada con éxito');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
