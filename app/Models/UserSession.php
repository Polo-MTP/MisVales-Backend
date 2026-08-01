<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class UserSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'token_hash',
        'factors_completed',
        'is_fully_authenticated',
        'expires_at',
        'last_activity_at',
    ];

    protected $casts = [
        'factors_completed' => 'integer',
        'is_fully_authenticated' => 'boolean',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'session_id');
    }
}
