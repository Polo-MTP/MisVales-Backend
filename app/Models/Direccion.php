<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'calle',
    'colonia',
    'numero_ext',
    'numero_int',
    'codigo_postal',
    'estado',
    'ciudad',
])]
final class Direccion extends Model
{
    use HasFactory;

    protected $table = 'direcciones';

    /**
     * latitud/longitud quedan fuera de Fillable a propósito: solo las escribe
     * DireccionObserver vía geocodificación, nunca deben venir de un request del cliente.
     */
    protected $casts = [
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
    ];

    /**
     * Registro de datos personales que usa esta dirección.
     */
    public function datosPersonales(): HasOne
    {
        return $this->hasOne(DatosPersonales::class, 'direccion_id');
    }
}
