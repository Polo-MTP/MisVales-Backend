<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int|null $role_id
 * @property int|null $datos_id
 * @property int|null $sucursal_id
 * @property bool $is_active
 * @property bool $is_locked
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role_id',
    'datos_id',
    'sucursal_id',
    'is_active',
    'is_locked',
    'failed_attempts',
    'locked_until',
])]
#[Hidden([
    'password',
    'remember_token',
])]
final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * Rol que determina los permisos del usuario.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Sucursal a la que pertenece el usuario.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    /**
     * Distribuidora de la que este usuario es dueño (rol Distribuidora).
     */
    public function distribuidora(): HasOne
    {
        return $this->hasOne(Distribuidora::class, 'usuario_id');
    }

    /**
     * Datos personales del usuario.
     */
    public function datosPersonales(): BelongsTo
    {
        return $this->belongsTo(DatosPersonales::class, 'datos_id');
    }

    /**
     * Métodos de autenticación multifactor registrados por el usuario.
     */
    public function mfaMethods(): HasMany
    {
        return $this->hasMany(MfaMethod::class);
    }

    /**
     * Sesiones (tokens) activas o históricas del usuario.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Acciones auditadas realizadas por el usuario.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Intentos de login asociados a este usuario.
     */
    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }
}
