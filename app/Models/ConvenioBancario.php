<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'banco',
    'convenio',
    'clabe',
    'sucursal_id',
    'activo',
])]
final class ConvenioBancario extends Model
{
    use HasFactory;

    protected $table = 'convenios_bancarios';

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Sucursal dueña de este convenio bancario.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
