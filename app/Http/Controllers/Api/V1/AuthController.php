<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\ResendVerificationRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\LoginService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

final class AuthController extends ApiController
{
    /**
     * Autentica al usuario; puede devolver un token directo o pedir un reto MFA/setup
     * según la configuración de la cuenta.
     */
    public function login(LoginRequest $request, LoginService $loginService): JsonResponse
    {
        Log::debug('AuthController: Iniciando proceso de login', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);

        $result = $loginService->login(
            [
                'email' => $request->email,
                'password' => $request->password,
            ],
            $request->ip() ?? '127.0.0.1',
            $request->userAgent()
        );

        // Nunca loguear $result completo: trae el plainTextToken de Sanctum (o el
        // setup_url firmado del MFA) en texto plano -- cualquiera con lectura del log
        // podría secuestrar la sesión sin necesitar la contraseña.
        Log::debug('AuthController: Proceso de login terminado', [
            'email' => $request->email,
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
        ]);

        if (! $result['success']) {
            return $this->error(
                message: (string) $result['message'],
                code: (int) $result['code']
            );
        }

        // Si requiere reto MFA o setup
        if (isset($result['requires_mfa']) || isset($result['requires_setup'])) {
            return $this->success(
                data: $result,
                message: (string) $result['message']
            );
        }

        return $this->success(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ],
            message: (string) $result['message']
        );
    }

    /**
     * Cierra la sesión actual del usuario autenticado.
     */
    public function logout(Request $request, LoginService $loginService): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        Log::debug('AuthController: Iniciando proceso de logout', [
            'user_id' => $user?->id,
        ]);

        $result = $loginService->logout($user);

        return $this->success(message: (string) $result['message']);
    }

    /**
     * Devuelve los datos del usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success(new UserResource($user->load('role')));
    }

    /**
     * Marca el email del usuario autenticado como verificado.
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(message: 'Email ya verificado.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->success(message: 'Email verificado exitosamente.');
    }

    /**
     * Reenvía el correo de verificación de email al usuario indicado.
     */
    public function resendVerificationEmail(ResendVerificationRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $request->email)->first();

        if (! $user) {
            return $this->notFound('Usuario no encontrado.');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->error('Email ya verificado.', 400);
        }

        $user->sendEmailVerificationNotification();

        return $this->success(message: 'Correo de verificación reenviado exitosamente.');
    }

    /**
     * Envía el enlace de restablecimiento de contraseña al correo indicado.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success(message: 'Enlace de restablecimiento enviado a tu correo electrónico.');
        }

        return $this->error('No se pudo enviar el enlace de restablecimiento.', 500);
    }

    /**
     * Restablece la contraseña usando el token enviado por correo y revoca los tokens de API existentes.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(message: 'Contraseña restablecida exitosamente.');
        }

        return $this->error(
            match ($status) {
                Password::INVALID_TOKEN => 'Token de restablecimiento inválido o expirado.',
                Password::INVALID_USER => 'Usuario no encontrado.',
                default => 'No se pudo restablecer la contraseña.',
            },
            400
        );
    }
}
