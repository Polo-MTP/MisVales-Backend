<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
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
 * @property int|null $gerente_id
 * @property string|null $rfc
 * @property string|null $referencia_laboral
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
    'gerente_id',
    'rfc',
    'referencia_laboral',
    'is_active',
    'is_locked',
    'failed_attempts',
    'locked_until',
])]
#[Hidden([
    'password',
    'remember_token',
    'es_el_unico_administrador',
])]
final class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * Mantiene 'es_el_unico_administrador' en sincronía con el rol en CADA guardado (alta o
     * cambio de rol) -- true solo si el usuario es Administrador, null para cualquier otro
     * caso. El índice único de esa columna (ver la migración) es lo que de verdad hace
     * imposible que exista un segundo Administrador; esto solo la mantiene actualizada para
     * que ese candado no dependa de que alguien la llene a mano.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->exists && ! $user->isDirty('role_id')) {
                return;
            }

            $user->es_el_unico_administrador = $user->role_id !== null
                && Role::query()->whereKey($user->role_id)->where('name', 'Administrador')->exists()
                ? true
                : null;
        });
    }

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
     * Gerente de Sucursal al que reporta este usuario (Coordinador, Verificador o Cajera).
     */
    public function gerente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_id');
    }

    /**
     * Usuarios (Coordinador, Verificador, Cajera) que reportan a este Gerente de Sucursal.
     */
    public function personalACargo(): HasMany
    {
        return $this->hasMany(User::class, 'gerente_id');
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
            'es_el_unico_administrador' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }
}
