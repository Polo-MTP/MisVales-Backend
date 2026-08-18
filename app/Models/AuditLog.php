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
    'session_id',
    'action',
    'resource',
    'ip_address',
])]
final class AuditLog extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'audit_log';

    /**
     * Usuario que realizó la acción registrada.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sesión bajo la cual se realizó la acción.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'session_id');
    }
}
