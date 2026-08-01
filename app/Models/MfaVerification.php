<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mfa_method_id',
    'code_hash',
    'used',
    'expires_at',
])]
final class MfaVerification extends Model
{
    use HasFactory;
    use HasUuids;

    protected $casts = [
        'used' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function mfaMethod(): BelongsTo
    {
        return $this->belongsTo(MfaMethod::class);
    }
}
