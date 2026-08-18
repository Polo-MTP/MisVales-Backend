<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\AltaProveedor;

use Illuminate\Foundation\Http\FormRequest;

final class CrearSolicitudProveedorRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede capturar una solicitud de alta de proveedor.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Datos del negocio (distribuidora)
            'razon_social' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', 'size:13', 'unique:distribuidoras,rfc', 'unique:solicitudes_proveedor,rfc'],

            // Datos Personales
            'nombre' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'curp' => ['required', 'string', 'size:18', 'unique:datos_personales,curp'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'lugar_nacimiento' => ['nullable', 'string', 'max:255'],

            // Dirección
            'calle' => ['required', 'string', 'max:255'],
            'colonia' => ['required', 'string', 'max:255'],
            'numero_ext' => ['required', 'string', 'max:50'],
            'numero_int' => ['nullable', 'string', 'max:50'],
            'codigo_postal' => ['required', 'string', 'max:10'],
            'estado' => ['required', 'string', 'max:255'],
            'ciudad' => ['required', 'string', 'max:255'],

            // Datos adicionales del solicitante (familiares, vehículo, vivienda, referencia laboral)
            'datos_familiares' => ['nullable', 'array'],
            'datos_vehiculos' => ['nullable', 'array'],
            'datos_vivienda' => ['nullable', 'array'],
            'referencia_laboral' => ['nullable', 'string', 'max:255'],

            // Asignación opcional de Verificador por el Coordinador
            'verificador_id' => ['nullable', 'integer', 'exists:users,id'],

            // Evidencias iniciales (URLs de archivos subidos al servidor de imágenes)
            'evidencias' => ['nullable', 'array'],
            'evidencias.*.tipo_documento' => ['required', 'string', 'max:100'],
            'evidencias.*.url_archivo' => ['required', 'string', 'max:500'],
        ];
    }
}
