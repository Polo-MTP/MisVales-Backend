<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class EmailOtpService
{
    /**
     * Verifica la validez del código OTP de correo electrónico ingresado por el usuario.
     *
     * @param  array{user_id: int, code: string}  $data
     * @return array<string, mixed>
     */
    public function verify(array $data): array
    {
        $userId = $data['user_id'] ?? null;
        $code = $data['code'] ?? null;

        Log::debug('EmailOtpService: Iniciando verificación de código OTP del tercer factor', [
            'user_id' => $userId,
        ]);

        if (! $userId || ! $code) {
            Log::debug('EmailOtpService: Parámetros de verificación OTP inválidos o vacíos', [
                'user_id' => $userId,
            ]);

            return [
                'success' => false,
                'message' => 'Por favor, ingresa el código completo de 6 dígitos que te enviamos.',
                'code' => 400,
            ];
        }

        $cachedCode = Cache::get('email_otp_'.$userId);

        if (! $cachedCode) {
            Log::debug('EmailOtpService: El código OTP ha expirado o no se encuentra en caché', [
                'user_id' => $userId,
            ]);

            $user = User::query()->find($userId);
            if ($user) {
                LoginAttempt::record($user->id, $user->email, 'failed_otp_expired', 3, 'El código OTP expiró o no existe.');
            }

            return [
                'success' => false,
                'message' => 'El código expiró o no existe. Intenta iniciar sesión de nuevo.',
                'code' => 401,
            ];
        }

        if ($cachedCode !== $code) {
            Log::debug('EmailOtpService: Código OTP incorrecto ingresado por el usuario', [
                'user_id' => $userId,
            ]);

            $user = User::query()->find($userId);
            if ($user) {
                LoginAttempt::record($user->id, $user->email, 'failed_otp', 3, 'Código OTP ingresado incorrecto.');
            }

            return [
                'success' => false,
                'message' => 'El código ingresado es incorrecto.',
                'code' => 401,
            ];
        }

        Cache::forget('email_otp_'.$userId);

        /** @var User $user */
        $user = User::query()->with('role')->findOrFail($userId);

        Log::debug('EmailOtpService: Código OTP correcto. Autenticación de tercer factor exitosa', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        LoginAttempt::record($user->id, $user->email, 'success_factor_3', 3, 'Tercer factor exitoso. Autenticado.');

        return [
            'success' => true,
            'message' => 'Tercer Factor verificado con éxito.',
            'user' => $user,
            'token' => $token,
            'code' => 200,
        ];
    }
}
