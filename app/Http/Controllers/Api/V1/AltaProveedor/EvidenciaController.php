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

        // A diferencia de show()/verificar()/aprobarORechazar(), este endpoint no validaba
        // sucursal -- cualquier Coordinador/Verificador/Gerente de Sucursal autenticado (el
        // middleware de rol ya los deja pasar) podía subir evidencia a una solicitud de
        // CUALQUIER sucursal solo conociendo su ID. Mismo criterio que el resto del flujo:
        // Gerente General/Administrador ven todas, el resto solo la suya.
        if ($usuario->role?->name !== 'Gerente General' && $usuario->role?->name !== 'Administrador' && $usuario->sucursal_id !== $solicitud->sucursal_id) {
            return $this->forbidden('Acceso Denegado. No tienes permisos para subir evidencia a solicitudes de otra sucursal.');
        }

        $disk = (config('filesystems.default') === 's3' || ! empty(config('filesystems.disks.s3.bucket')))
            ? 's3'
            : 'public';

        $ruta = Storage::disk($disk)->putFile('evidencias', $request->file('archivo'), 'public');
        $url = Storage::disk($disk)->url($ruta);

        /** @var Evidencia $evidencia */
        $evidencia = Evidencia::query()->create([
            'solicitud_id' => $solicitud->id,
            'entidad_tipo' => 'SolicitudProveedor',
            'entidad_id' => $solicitud->id,
            'tipo_documento' => $request->string('tipo_documento'),
            'url_archivo' => $url,
            'subido_por' => $usuario->id,
            'fecha_subida' => now(),
        ]);

        return $this->created(
            data: new EvidenciaResource($evidencia),
            message: 'Imagen subida exitosamente.'
        );
    }
}
