<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
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
#[Table(table: 'direcciones')]
final class Direccion extends Model
{
    use HasFactory;

    public function datosPersonales(): HasOne
    {
        return $this->hasOne(DatosPersonales::class, 'direccion_id');
    }
}
