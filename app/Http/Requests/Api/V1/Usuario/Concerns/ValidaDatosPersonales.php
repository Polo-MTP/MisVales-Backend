<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario\Concerns;

/**
 * Mismo expediente que captura CrearSolicitudProveedorRequest (alta de distribuidora):
 * Datos Personales + Dirección + RFC + Referencia Laboral. El personal interno (Gerente de
 * Sucursal, Administrador, Coordinador/Verificador/Cajera, Gerente General) ahora pasa por el
 * mismo formulario -- sin el flujo de verificación en campo (Coordinador/Verificador), que
 * solo aplica a una distribuidora externa; aquí el alta es directa por quien tiene autoridad
 * para crear ese rol.
 */
trait ValidaDatosPersonales
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasDatosPersonales(): array
    {
        return [
            'rfc' => ['required', 'string', 'size:13', 'unique:users,rfc'],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'curp' => ['required', 'string', 'size:18', 'unique:datos_personales,curp'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'lugar_nacimiento' => ['nullable', 'string', 'max:255'],

            'calle' => ['required', 'string', 'max:255'],
            'colonia' => ['required', 'string', 'max:255'],
            'numero_ext' => ['required', 'string', 'max:50'],
            'numero_int' => ['nullable', 'string', 'max:50'],
            'codigo_postal' => ['required', 'string', 'max:10'],
            'estado' => ['required', 'string', 'max:255'],
            'ciudad' => ['required', 'string', 'max:255'],

            'referencia_laboral' => ['nullable', 'string', 'max:255'],
        ];
    }
}
