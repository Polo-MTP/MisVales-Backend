<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'clave',
    'valor',
    'tipo_dato',
    'vigente_desde',
    'vigente_hasta',
    'modificado_por',
])]
final class Configuracion extends Model
{
    use HasFactory;

    protected $table = 'configuraciones';

    protected $casts = [
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function modificadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificado_por');
    }

    /**
     * Cast del valor string a su tipo de dato real.
     */
    public function getValorCasteadoAttribute(): mixed
    {
        return match ($this->tipo_dato) {
            'decimal' => (float) $this->valor,
            'int' => (int) $this->valor,
            'boolean' => filter_var($this->valor, FILTER_VALIDATE_BOOLEAN),
            default => $this->valor,
        };
    }
}
