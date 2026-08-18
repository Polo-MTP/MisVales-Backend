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
    'email_attempted',
    'ip_address',
    'user_agent',
    'status',
    'factor_step',
    'failure_reason',
])]
final class LoginAttempt extends Model
{
    use HasFactory;
    use HasUuids;

    protected $casts = [
        'factor_step' => 'integer',
    ];

    /**
     * Registra un intento de login (éxito o fallo) tomando IP/user-agent de la request actual.
     */
    public static function record(?int $userId, string $email, string $status, int $factorStep = 1, ?string $failureReason = null): void
    {
        self::query()->create([
            'user_id' => $userId,
            'email_attempted' => $email,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent(),
            'status' => $status,
            'factor_step' => $factorStep,
            'failure_reason' => $failureReason,
        ]);
    }

    /**
     * Usuario asociado al intento de login (null si el email no correspondía a ningún usuario).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
