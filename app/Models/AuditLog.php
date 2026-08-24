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
    'sucursal_id',
    'session_id',
    'action',
    'modulo',
    'nivel',
    'descripcion',
    'resource',
    'ip_address',
    'user_agent',
    'datos_adicionales',
])]
final class AuditLog extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'audit_log';

    protected $casts = [
        'datos_adicionales' => 'array',
    ];

    /**
     * Usuario que realizó la acción registrada.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sucursal asociada a la acción.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Sesión bajo la cual se realizó la acción.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'session_id');
    }
}
