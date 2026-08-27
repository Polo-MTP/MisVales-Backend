<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\AltaProveedor;

use App\Models\SolicitudProveedor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VerificarSolicitudProveedorRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviar el dictamen (la restricción de rol
     * Verificador se aplica en el controller/policy).
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
            'cumple' => ['required', 'boolean'],
            'comentario_verificador' => ['required', 'string'],
            'dispositivo' => ['nullable', 'string', 'max:255'],
            'motivo_edicion' => ['nullable', 'string', 'max:255'],

            // Datos Personales opcionalmente editados por el Verificador
            'datos_personales' => ['nullable', 'array'],
            'datos_personales.nombre' => ['nullable', 'string', 'max:255'],
            'datos_personales.apellido_paterno' => ['nullable', 'string', 'max:255'],
            'datos_personales.apellido_materno' => ['nullable', 'string', 'max:255'],
            // Sin 'unique' aquí, el Verificador podía editar la CURP a un valor ya usado por
            // otra persona: el guardado en el servicio tronaba con un 500 crudo (violación de
            // constraint de BD) en vez de un 422 limpio. ignore() excluye los datos_personales
            // de esta MISMA solicitud (si no cambió la CURP, no debe fallar contra sí misma).
            'datos_personales.curp' => [
                'nullable',
                'string',
                'size:18',
                Rule::unique('datos_personales', 'curp')->ignore(
                    $this->route('solicitud') instanceof SolicitudProveedor ? $this->route('solicitud')->datos_id : null,
                    'id'
                ),
            ],
            'datos_personales.fecha_nacimiento' => ['nullable', 'date'],
            'datos_personales.lugar_nacimiento' => ['nullable', 'string', 'max:255'],

            // Dirección opcionalmente editada por el Verificador
            'direccion' => ['nullable', 'array'],
            'direccion.calle' => ['nullable', 'string', 'max:255'],
            'direccion.colonia' => ['nullable', 'string', 'max:255'],
            'direccion.numero_ext' => ['nullable', 'string', 'max:50'],
            'direccion.numero_int' => ['nullable', 'string', 'max:50'],
            'direccion.codigo_postal' => ['nullable', 'string', 'max:10'],
            'direccion.estado' => ['nullable', 'string', 'max:255'],
            'direccion.ciudad' => ['nullable', 'string', 'max:255'],

            // Nuevas evidencias fotográficas de la visita física
            'evidencias' => ['nullable', 'array'],
            'evidencias.*.tipo_documento' => ['required', 'string', 'max:100'],
            'evidencias.*.url_archivo' => ['required', 'string', 'max:500'],
        ];
    }
}
