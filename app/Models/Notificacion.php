<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sucursal_id',
    'user_id',
    'accion',
    'recurso',
])]
final class Notificacion extends Model
{
    use HasFactory;

    protected $table = 'notificaciones';

    /**
     * Sucursal a la que pertenece esta notificación.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Usuario que generó la acción notificada.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
