<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateConfiguracionFechasRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviar la solicitud (la restricción de rol
     * se aplica en el controller/policy).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * dia_corte y dia_corte_2 son los dos días de corte quincenales (primera y segunda
     * quincena) y siempre van en pareja: si se configura uno, el otro también es obligatorio
     * -- por eso ambos son 'required', no 'sometimes'. 'different:dia_corte' evita configurar
     * el mismo día dos veces, lo que en la práctica dejaría un solo corte al mes en vez de dos.
     */
    public function rules(): array
    {
        return [
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'dia_corte' => ['required', 'integer', 'between:1,31', 'different:dia_corte_2'],
            'dia_corte_2' => ['required', 'integer', 'between:1,31', 'different:dia_corte'],
            'dia_limite_pago' => ['required', 'integer', 'between:1,31'],
            'dias_pago_anticipado' => ['required', 'integer', 'between:0,30'],
        ];
    }
}
