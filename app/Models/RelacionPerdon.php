<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'distribuidora_id',
    'relacion_id',
    'numero_perdon',
    'autorizado_por',
    'motivo',
])]
final class RelacionPerdon extends Model
{
    use HasFactory;

    protected $table = 'relacion_perdones';

    protected $casts = [
        'numero_perdon' => 'integer',
    ];

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class);
    }

    public function relacion(): BelongsTo
    {
        return $this->belongsTo(Relacion::class);
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }
}
