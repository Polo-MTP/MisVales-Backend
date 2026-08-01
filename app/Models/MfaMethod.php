<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfaMethod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'mfa_type_id',
        'secret',
        'factor_step',
        'is_verified',
        'is_active',
    ];

    protected $casts = [
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
