<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'nombre',
    'apellido_paterno',
    'apellido_materno',
    'curp',
    'direccion_id',
    'fecha_nacimiento',
    'lugar_nacimiento',
])]
#[Table(table: 'datos_personales')]
final class DatosPersonales extends Model
{
    use HasFactory;

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];

    public function direccion(): BelongsTo
    {
        return $this->belongsTo(Direccion::class, 'direccion_id');
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(User::class, 'datos_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudProveedor::class, 'datos_id');
    }
}
