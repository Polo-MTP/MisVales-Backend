<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sucursal;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SucursalUpdateRequest extends FormRequest
{
    /**
     * El Gerente General edita sucursales normales, pero la sucursal matriz -- ya sea la
     * actual o una que el request intente volver matriz -- solo la toca Administrador. Es el
     * mismo criterio que SucursalStoreRequest: quien controla la matriz controla a dónde se
     * asigna cualquier Gerente General nuevo.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $sucursal = $this->sucursalActual();
        $esOSeraMatriz = ($sucursal && $sucursal->es_matriz) || $this->boolean('es_matriz');

        if ($esOSeraMatriz) {
            return $user->role?->name === 'Administrador';
        }

        return $user->role?->name === 'Gerente General';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sucursal = $this->sucursalActual();
        $id = $sucursal?->id ?? $this->route('sucursal');

        return [
            'nombre' => 'required|string|max:100',
            'codigo' => ['required', 'string', 'max:20', Rule::unique('sucursales', 'codigo')->ignore($id)],
            'es_matriz' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail) use ($sucursal): void {
                if (! $sucursal) {
                    return;
                }

                if ($this->boolean('es_matriz') && ! $sucursal->es_matriz && Sucursal::query()->where('es_matriz', true)->exists()) {
                    $fail('Ya existe una sucursal matriz. Solo puede haber una.');
                }

                if (! $this->boolean('es_matriz') && $sucursal->es_matriz) {
                    $fail('No se le puede quitar el estatus de matriz a la sucursal matriz actual.');
                }
            }],
            'is_active' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail) use ($sucursal): void {
                if ($sucursal && $sucursal->es_matriz && ! $this->boolean('is_active')) {
                    $fail('No se puede deshabilitar la sucursal matriz.');
                }
            }],
        ];
    }

    /**
     * '{sucursal}' ya llega resuelto por route model binding (una instancia de Sucursal, no un
     * id) para cuando el request es válido -- pero si la ruta no matcheó ningún registro, llega
     * como null. find() truena si se le pasa una instancia de modelo en vez de un id, así que
     * hay que distinguir los dos casos aquí en vez de asumir uno solo.
     */
    private function sucursalActual(): ?Sucursal
    {
        $parametro = $this->route('sucursal');

        return $parametro instanceof Sucursal ? $parametro : Sucursal::query()->find($parametro);
    }
}
