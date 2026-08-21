<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Storage\SpacesStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class UploadController extends ApiController
{
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
            'content_type' => 'required|string|max:100',
            'folder' => 'nullable|string|max:50',
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
