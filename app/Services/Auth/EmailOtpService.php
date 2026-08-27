<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class EmailOtpService
{
    /**
     * Verifica la validez del código OTP de correo electrónico ingresado por el usuario.
     *
     * $otp_token es un token opaco de un solo uso (ver MfaService::verify()), no el user_id en
     * crudo -- eso es lo que ata esta petición al login que de verdad pasó los primeros dos
     * factores, en vez de bastar con adivinar/conocer el user_id (entero secuencial) de otra
     * persona para poder mandarle intentos de código directo a este endpoint.
     *
     * @param  array{otp_token: string, code: string}  $data
     * @return array<string, mixed>
     */
    public function verify(array $data): array
    {
        $otpToken = $data['otp_token'] ?? null;
        $code = $data['code'] ?? null;

        Log::debug('EmailOtpService: Iniciando verificación de código OTP del tercer factor');

        if (! $otpToken || ! $code) {
            Log::debug('EmailOtpService: Parámetros de verificación OTP inválidos o vacíos');

            return [
                'success' => false,
                'message' => 'Por favor, ingresa el código completo de 6 dígitos que te enviamos.',
                'code' => 400,
            ];
        }

        $cached = Cache::get('email_otp_'.$otpToken);

        if (! $cached) {
            Log::debug('EmailOtpService: El código OTP ha expirado o el token no existe');

            return [
                'success' => false,
                'message' => 'El código expiró o no existe. Intenta iniciar sesión de nuevo.',
                'code' => 401,
            ];
        }

        $userId = $cached['user_id'];
        $cachedCode = $cached['code'];

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

        Cache::forget('email_otp_'.$otpToken);

        /** @var User $user */
        $user = User::query()->with('role')->findOrFail($userId);

        Log::debug('EmailOtpService: Código OTP correcto. Autenticación de tercer factor exitosa', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Auth::guard('web')->login($user);

        LoginAttempt::record($user->id, $user->email, 'success_factor_3', 3, 'Tercer factor exitoso. Autenticado.');

        return [
            'success' => true,
            'message' => 'Tercer Factor verificado con éxito.',
            'user' => $user,
            'code' => 200,
        ];
    }
}
