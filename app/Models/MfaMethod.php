<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'mfa_type_id',
    'secret',
    'factor_step',
    'is_verified',
    'is_active',
])]
final class MfaMethod extends Model
{
    use HasFactory;
    use HasUuids;

    protected $casts = [
        // El secreto TOTP es, junto con la contraseña, el segundo factor completo de la
        // cuenta: antes se guardaba en texto plano en la BD. Laravel lo cifra/descifra
        // solo con APP_KEY, transparente para el resto del código (MfaService, Google2FA...).
        'secret' => 'encrypted',
        'factor_step' => 'integer',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MfaType::class, 'mfa_type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
