<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'factor_count',
])]
final class Role extends Model
{
    use HasFactory;

    /**
     * Usuarios que tienen asignado este rol.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
