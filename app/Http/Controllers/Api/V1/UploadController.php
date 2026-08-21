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
     * application/javascript) queda fuera: el objeto se sube con ACL pública, así que
     * aceptar cualquier content_type permitiría alojar contenido arbitrario bajo el
     * dominio de confianza del bucket.
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
}
