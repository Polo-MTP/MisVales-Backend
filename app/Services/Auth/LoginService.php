<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\MfaMethod;
use App\Models\MfaType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

final class LoginService
{
    /**
     * Realiza el proceso de inicio de sesión validando credenciales y evaluando requerimientos de MFA.
     *
     * @param  array{email: string, password: string}  $data
     * @return array<string, mixed>
     */
    public function login(array $data, string $ipAddress): array
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

            $this->guardarEnHistorial(null, $data['email'], 'failed_user_not_found');

            return [
                'success' => false,
                'message' => 'Credenciales incorrectas.',
                'code' => 401,
            ];
        }

        if (! $user->is_active) {
            $this->guardarEnHistorial($user->id, $user->email, 'account_inactive');

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

            $this->guardarEnHistorial($user->id, $user->email, 'account_locked');

            return $this->generarMensajeDeBloqueo($user);
        }

        if (! Hash::check($data['password'], $user->password)) {
            $this->procesarContrasenaIncorrecta($user);

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

        return $this->procesarLoginExitoso($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(?User $user, Request $request): array
    {
        Log::debug('LoginService: Iniciando proceso de cierre de sesión', [
            'user_id' => $user?->id,
            'email' => $user?->email,
        ]);

        if ($user && $request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } elseif ($user && method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
            // Cliente sin sesión de navegador (Bearer token directo, ej. Postman o una futura
            // app móvil): Sanctum sigue soportando este modo en paralelo al de cookie.
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

    /**
     * Indica si la cuenta sigue dentro de la ventana de bloqueo temporal por intentos fallidos.
     */
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
            'message' => sprintf('Tu cuenta está bloqueada. Intenta de nuevo en %d minutos.', $minutosRestantes),
            'code' => 403,
        ];
    }

    private function procesarContrasenaIncorrecta(User $user): void
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
        $this->guardarEnHistorial($user->id, $user->email, 'failed_password');
    }

    /**
     * @return array<string, mixed>
     */
    private function procesarLoginExitoso(User $user): array
    {
        Log::debug('LoginService: Procesando login exitoso del primer factor', [
            'email' => $user->email,
        ]);

        $user->failed_attempts = 0;
        $user->is_locked = false;
        $user->locked_until = null;
        $user->save();

        $this->guardarEnHistorial($user->id, $user->email, 'success_factor_1');

        $factorCount = $user->role?->factor_count ?? 1;

        if ($factorCount > 1) {
            Log::debug('LoginService: Usuario requiere autenticación multifactor (MFA)', [
                'email' => $user->email,
                'factor_count' => $factorCount,
            ]);

            return $this->generarRetoMfa($user);
        }

        Log::debug('LoginService: Login de factor único exitoso y completado', [
            'email' => $user->email,
        ]);

        Auth::guard('web')->login($user);

        return [
            'success' => true,
            'message' => 'Login exitoso',
            'user' => $user->load('role'),
            'code' => 200,
        ];
    }

    private function guardarEnHistorial(?int $userId, string $email, string $status): void
    {
        LoginAttempt::record($userId, $email, $status, 1);
    }

    /**
     * Determina si el usuario ya tiene su segundo factor (TOTP) configurado y verificado.
     * Si no, pide setup (QR); si ya lo tiene, pide el código para continuar el login.
     *
     * @return array<string, mixed>
     */
    private function generarRetoMfa(User $user): array
    {
        /** @var MfaType|null $totpType */
        $totpType = MfaType::query()->where('type', 'totp')->first();

        $metodo = $totpType
            ? MfaMethod::query()->where('user_id', $user->id)->where('mfa_type_id', $totpType->id)->first()
            : null;

        if (! $metodo || ! $metodo->is_verified) {
            Log::debug('LoginService: Usuario sin segundo factor configurado, requiere setup', [
                'email' => $user->email,
            ]);

            $this->guardarEnHistorial($user->id, $user->email, 'requires_mfa_setup');

            // Firmado y de corta duración: solo quien acaba de demostrar la contraseña (este
            // mismo request) puede obtener el link para ver/generar el secreto TOTP. Sin esto,
            // mfa/setup?email= era alcanzable por cualquiera con solo adivinar un correo.
            $setupUrl = URL::temporarySignedRoute(
                'api.v1.mfa.setup',
                now()->addMinutes(10),
                ['email' => $user->email]
            );

            return [
                'success' => true,
                'requires_setup' => true,
                'email' => $user->email,
                'setup_url' => $setupUrl,
                'message' => 'Necesitas configurar tu autenticación de dos pasos. Escanea el código QR desde /mfa/setup.',
                'code' => 200,
            ];
        }

        Log::debug('LoginService: Usuario con segundo factor configurado, requiere código', [
            'email' => $user->email,
            'mfa_method_id' => $metodo->id,
        ]);

        $this->guardarEnHistorial($user->id, $user->email, 'requires_mfa_code');

        return [
            'success' => true,
            'requires_mfa' => true,
            'mfa_method_id' => (string) $metodo->id,
            'message' => 'Ingresa el código de tu aplicación de autenticación.',
            'code' => 200,
        ];
    }
}
