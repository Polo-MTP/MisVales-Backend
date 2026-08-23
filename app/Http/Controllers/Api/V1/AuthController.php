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

        // Nunca loguear $result completo: trae el setup_url firmado del MFA en texto
        // plano -- cualquiera con lectura del log podría usarlo antes de que expire.
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

        $result = $loginService->logout($user, $request);

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

        Log::debug('AuthController: Solicitud de restablecimiento de contraseña procesada', [
            'status' => $status,
        ]);

        // Se responde el mismo mensaje genérico sin importar si el correo existe, está
        // limitado por rate limit, etc. -- distinguir el caso "correo no encontrado" convierte
        // este endpoint en un oráculo para enumerar cuentas registradas (ver auditoría de
        // seguridad). Quien sí tiene una cuenta con ese correo recibe el enlace igual.
        return $this->success(message: 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.');
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
                // INVALID_TOKEN e INVALID_USER responden el mismo mensaje a propósito: distinguirlos
                // deja usar este endpoint para confirmar si un correo tiene cuenta, con solo mandar
                // cualquier token y ver cuál de los dos mensajes regresa (ver auditoría de seguridad).
                Password::INVALID_TOKEN, Password::INVALID_USER => 'Token de restablecimiento inválido o expirado.',
                default => 'No se pudo restablecer la contraseña.',
            },
            400
        );
    }
}
