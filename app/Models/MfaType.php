<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'is_active',
])]
final class MfaType extends Model
{
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Métodos de MFA registrados de este tipo.
     */
    public function mfaMethods(): HasMany
    {
        return $this->hasMany(MfaMethod::class, 'mfa_type_id');
    }
}
