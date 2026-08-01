<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

final class LoginService
{
    /**
     * Realiza el proceso de inicio de sesión validando credenciales y evaluando requerimientos de MFA.
     *
     * @param array{email: string, password: string} $data
     * @return array<string, mixed>
     */
    public function login(array $data, string $ipAddress, ?string $userAgent): array
    {
        Log::debug('LoginService: Iniciando autenticación de usuario', [
            'email' => $data['email'],
            'ip' => $ipAddress,
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user) {
            Log::debug('LoginService: Usuario no encontrado en la base de datos', [
                'email' => $data['email'],
            ]);

            $this->guardarEnHistorial(null, $data['email'], $ipAddress, $userAgent, 'failed_user_not_found');

            return [
                'success' => false,
                'message' => 'Credenciales incorrectas.',
                'code' => 401,
            ];
        }

        if (! $user->is_active) {
            $this->guardarEnHistorial($user->id, $user->email, $ipAddress, $userAgent, 'account_inactive');

            return [
                'success' => false,
                'message' => 'Tu cuenta ha sido desactivada por un administrador. Contacta a soporte.',
                'code' => 403,
            ];
        }

        if ($this->laCuentaEstaBloqueada($user)) {
            Log::debug('LoginService: Intento de acceso a cuenta bloqueada temporalmente', [
                'email' => $user->email,
                'locked_until' => $user->locked_until,
            ]);

            $this->guardarEnHistorial($user->id, $user->email, $ipAddress, $userAgent, 'account_locked');

            return $this->generarMensajeDeBloqueo($user);
        }

        if (! Hash::check($data['password'], $user->password)) {
            $this->procesarContrasenaIncorrecta($user, $ipAddress, $userAgent);

            Log::debug('LoginService: Contraseña incorrecta ingresada', [
                'email' => $user->email,
                'failed_attempts' => $user->failed_attempts,
            ]);

            return [
                'success' => false,
                'message' => 'Credenciales incorrectas.',
                'code' => 401,
            ];
        }

        return $this->procesarLoginExitoso($user, $ipAddress, $userAgent);
    }

    private function laCuentaEstaBloqueada(User $user): bool
    {
        return $user->is_locked && $user->locked_until && $user->locked_until > now();
    }

    /**
     * @return array<string, mixed>
     */
    private function generarMensajeDeBloqueo(User $user): array
    {
        $minutosRestantes = $user->locked_until ? (int) now()->diffInMinutes($user->locked_until) : 15;

        return [
            'success' => false,
            'message' => "Tu cuenta está bloqueada. Intenta de nuevo en {$minutosRestantes} minutos.",
            'code' => 403,
        ];
    }

    private function procesarContrasenaIncorrecta(User $user, string $ipAddress, ?string $userAgent): void
    {
        $user->failed_attempts += 1;

        if ($user->failed_attempts >= 5) {
            $user->is_locked = true;
            $user->locked_until = now()->addMinutes(15);
            Log::debug('LoginService: La cuenta ha sido bloqueada por límite de intentos fallidos alcanzado', [
                'email' => $user->email,
                'failed_attempts' => $user->failed_attempts,
            ]);
        }

        $user->save();
        $this->guardarEnHistorial($user->id, $user->email, $ipAddress, $userAgent, 'failed_password');
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarLoginExitoso(User $user, string $ipAddress, ?string $userAgent): array
    {
        Log::debug('LoginService: Procesando login exitoso del primer factor', [
            'email' => $user->email,
        ]);

        $user->failed_attempts = 0;
        $user->is_locked = false;
        $user->locked_until = null;
        $user->save();

        $this->guardarEnHistorial($user->id, $user->email, $ipAddress, $userAgent, 'success_factor_1');

        // MFA desactivado temporalmente para pruebas directas en Postman / desarrollo
        /*
        $factorCount = $user->role?->factor_count ?? 1;

        if ($factorCount > 1) {
            Log::debug('LoginService: Usuario requiere autenticación multifactor (MFA)', [
                'email' => $user->email,
                'factor_count' => $factorCount,
            ]);

            return $this->generarRetoMfa($user);
        }
        */

        Log::debug('LoginService: Login de factor único exitoso y completado', [
            'email' => $user->email,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'success' => true,
            'message' => 'Login exitoso',
            'user' => $user->load('role'),
            'token' => $token,
            'code' => 200,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generarRetoMfa(User $user): array
    {
        Log::debug('LoginService: Generando reto MFA para el usuario', [
            'email' => $user->email,
        ]);

        $mfaMethod = $user->mfaMethods()
            ->whereHas('type', function ($q): void {
                $q->where('type', 'totp');
            })
            ->where('factor_step', 2)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->first();

        if (! $mfaMethod) {
            Log::debug('LoginService: MFA no configurado para el usuario. Generando URL de configuración firmada', [
                'email' => $user->email,
            ]);

            $signedUrl = URL::temporarySignedRoute(
                'api.v1.mfa.setup',
                now()->addMinutes(10),
                ['email' => $user->email]
            );

            return [
                'success' => true,
                'requires_setup' => true,
                'setup_url' => $signedUrl,
                'message' => 'MFA no configurado. Requiere vincular la app de autenticación.',
                'code' => 200,
            ];
        }

        Log::debug('LoginService: Reto MFA TOTP generado', [
            'email' => $user->email,
            'mfa_method_id' => $mfaMethod->id,
        ]);

        return [
            'success' => true,
            'requires_mfa' => true,
            'step' => 2,
            'mfa_method_id' => $mfaMethod->id,
            'message' => 'Contraseña correcta. Ingresa el código de tu app de autenticación.',
            'code' => 200,
        ];
    }

    private function guardarEnHistorial(?int $userId, string $email, string $ipAddress, ?string $userAgent, string $status): void
    {
        LoginAttempt::record($userId, $email, $status, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(?User $user): array
    {
        Log::debug('LoginService: Iniciando proceso de cierre de sesión', [
            'user_id' => $user?->id,
            'email' => $user?->email,
        ]);

        if ($user && method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        Log::debug('LoginService: Sesión cerrada exitosamente', [
            'user_id' => $user?->id,
        ]);

        return [
            'success' => true,
            'message' => 'Sesión cerrada exitosamente.',
            'code' => 200,
        ];
    }
}
