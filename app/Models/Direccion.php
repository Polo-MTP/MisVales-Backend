<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Direccion extends Model
{
    use HasFactory;

    protected $table = 'direcciones';

    protected $fillable = [
        'calle',
        'colonia',
        'numero_ext',
        'numero_int',
        'codigo_postal',
        'estado',
        'ciudad',
    ];

    public function datosPersonales(): HasOne
    {
        return $this->hasOne(DatosPersonales::class, 'direccion_id');
    }
}
