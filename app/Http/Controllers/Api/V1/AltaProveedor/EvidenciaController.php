<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AltaProveedor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\AltaProveedor\SubirEvidenciaRequest;
use App\Http\Resources\AltaProveedor\EvidenciaResource;
use App\Models\Evidencia;
use App\Models\SolicitudProveedor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Sube el archivo real de una evidencia del alta de proveedor (ej. logo, fachada, identificación).
 * Complementa (no reemplaza) el flujo existente de SolicitudProveedorController, donde el
 * cliente puede seguir enviando `url_archivo` como string si ya subió el archivo a otro lado.
 */
final class EvidenciaController extends ApiController
{
    /**
     * Sube el archivo de una evidencia y la asocia a la solicitud de alta de proveedor.
     */
    public function store(SubirEvidenciaRequest $request, SolicitudProveedor $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        // Siempre el disco 'public', sin importar FILESYSTEM_DISK: esta evidencia necesita URL
        // pública de verdad, y 'local' (el default de la app) no tiene 'url' configurada — con
        // config('filesystems.default') aquí, en cualquier entorno donde FILESYSTEM_DISK=local
        // (dev, test, y producción hasta hoy) la subida "funcionaba" pero la URL guardada
        // quedaba rota. Si algún día se quiere mandar esto a Spaces, cambiar el driver del
        // disco 'public' en config/filesystems.php, no acoplar esta ruta al default global.
        $ruta = Storage::disk('public')->putFile('evidencias', $request->file('archivo'));

        /** @var Evidencia $evidencia */
        $evidencia = Evidencia::query()->create([
            'solicitud_id' => $solicitud->id,
            'entidad_tipo' => 'SolicitudProveedor',
            'entidad_id' => $solicitud->id,
            'tipo_documento' => $request->string('tipo_documento'),
            'url_archivo' => Storage::disk('public')->url($ruta),
            'subido_por' => $usuario->id,
            'fecha_subida' => now(),
        ]);

        return $this->created(
            data: new EvidenciaResource($evidencia),
            message: 'Imagen subida exitosamente.'
        );
    }
}
