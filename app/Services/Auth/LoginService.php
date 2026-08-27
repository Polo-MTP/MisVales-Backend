<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\MfaMethod;
use App\Models\MfaType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

final class LoginService
{
    /**
     * Hash bcrypt válido de un valor que nadie va a mandar como password -- se usa SOLO para
     * que Hash::check() le cueste al servidor lo mismo (el costo real está en bcrypt, no en la
     * comparación en sí) cuando el email no existe. Sin esto, "usuario no encontrado" regresa
     * casi instantáneo (solo un SELECT) mientras que "usuario existe, password incorrecto" paga
     * el costo de bcrypt -- un atacante puede distinguir ambos casos por tiempo de respuesta
     * aunque el mensaje sea idéntico, exactamente el mismo riesgo de enumeración de cuentas que
     * forgotPassword()/resetPassword() ya evitan a propósito con el mismo mensaje genérico.
     */
    private const string HASH_SENUELO = '$2y$12$VDJiBUhPQIz51gVbuUtlWeEn/tRCPTCwU3FA2AOJOVhTPBU6/oto6';

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
            // Paga el mismo costo de bcrypt que pagaría si el usuario sí existiera pero la
            // contraseña fuera incorrecta -- ver HASH_SENUELO.
            Hash::check($data['password'], self::HASH_SENUELO);

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

    /**
     * lockForUpdate(): dos intentos de login fallidos casi simultáneos para la misma cuenta
     * (ej. un ataque de fuerza bruta disparando peticiones en paralelo desde varias IPs, cada
     * una esquivando el throttle:auth que es por IP) leían el mismo failed_attempts viejo y el
     * segundo en guardar pisaba el conteo del primero -- se perdían intentos y el bloqueo a
     * los 5 intentos podía nunca dispararse bajo concurrencia real.
     */
    private function procesarContrasenaIncorrecta(User $user): void
    {
        DB::transaction(function () use ($user): void {
            /** @var User $usuarioBloqueado */
            $usuarioBloqueado = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $usuarioBloqueado->failed_attempts += 1;

            if ($usuarioBloqueado->failed_attempts >= 5) {
                $usuarioBloqueado->is_locked = true;
                $usuarioBloqueado->locked_until = now()->addMinutes(15);
                Log::debug('LoginService: La cuenta ha sido bloqueada por límite de intentos fallidos alcanzado', [
                    'email' => $usuarioBloqueado->email,
                    'failed_attempts' => $usuarioBloqueado->failed_attempts,
                ]);
            }

            $usuarioBloqueado->save();

            // El caller (login()) sigue usando esta misma instancia $user después de esta
            // llamada (para su propio log y para decidir el mensaje de respuesta) -- se
            // refleja aquí el resultado real, ya no el que traía antes de la transacción.
            $user->failed_attempts = $usuarioBloqueado->failed_attempts;
            $user->is_locked = $usuarioBloqueado->is_locked;
            $user->locked_until = $usuarioBloqueado->locked_until;
        });

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
