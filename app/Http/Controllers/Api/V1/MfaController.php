<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\ConfirmMfaSetupRequest;
use App\Http\Requests\Api\V1\VerifyMfaRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\MfaMethod;
use App\Services\Auth\EmailOtpService;
use App\Services\Auth\MfaService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

final class MfaController extends ApiController
{
    /**
     * Verifica el código MFA (TOTP) del reto de login; puede requerir un OTP por correo adicional.
     */
    public function verify(VerifyMfaRequest $request, MfaService $mfaService): JsonResponse
    {
        Log::debug('MfaController: Iniciando verificación de código MFA', [
            'mfa_method_id' => $request->mfa_method_id,
        ]);

        $result = $mfaService->verify([
            'mfa_method_id' => $request->mfa_method_id,
            'code' => $request->code,
        ]);

        // Nunca loguear $result completo: si tiene éxito trae el plainTextToken de
        // Sanctum en texto plano.
        Log::debug('MfaController: Verificación de código MFA terminada', [
            'mfa_method_id' => $request->mfa_method_id,
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
        ]);

        if (! $result['success']) {
            return $this->error(
                message: (string) $result['message'],
                code: (int) $result['code']
            );
        }

        if (isset($result['requires_email_otp'])) {
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
     * Verifica el código OTP enviado por correo como segundo paso del reto MFA.
     */
    public function verifyEmailOtp(VerifyOtpRequest $request, EmailOtpService $emailOtpService): JsonResponse
    {
        Log::debug('MfaController: Iniciando verificación de código OTP por correo', [
            'user_id' => $request->user_id,
        ]);

        $result = $emailOtpService->verify([
            'user_id' => (int) $request->user_id,
            'code' => $request->code,
        ]);

        // Nunca loguear $result completo: si tiene éxito trae el plainTextToken de
        // Sanctum en texto plano.
        Log::debug('MfaController: Verificación de código OTP por correo terminada', [
            'user_id' => $request->user_id,
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
        ]);

        if (! $result['success']) {
            return $this->error(
                message: (string) $result['message'],
                code: (int) $result['code']
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
     * Genera los datos (secreto/QR) para que el usuario configure su método MFA.
     */
    public function showSetup(Request $request, MfaService $mfaService): JsonResponse
    {
        $email = $request->query('email');

        if (! is_string($email) || ($email === '' || $email === '0')) {
            return $this->error('El parámetro email es obligatorio.', 400);
        }

        Log::debug('MfaController: Generando datos de setup MFA', ['email' => $email]);

        try {
            $setupData = $mfaService->generateSetupData($email);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->success(
            data: $setupData,
            message: 'Datos de configuración MFA generados exitosamente.'
        );
    }

    /**
     * Confirma el setup de MFA validando el primer código generado por el dispositivo del usuario.
     */
    public function confirmSetup(ConfirmMfaSetupRequest $request): JsonResponse
    {
        Log::debug('MfaController: Confirmando configuración MFA', [
            'mfa_method_id' => $request->mfa_method_id,
        ]);

        /** @var MfaMethod $method */
        $method = MfaMethod::query()->findOrFail($request->mfa_method_id);

        $google2fa = new Google2FA();
        $isValid = $google2fa->verifyKey($method->secret, $request->code);

        if ($isValid) {
            Log::debug('MfaController: Verificación exitosa de llave MFA', [
                'mfa_method_id' => $request->mfa_method_id,
            ]);

            $method->is_verified = true;
            $method->save();

            return $this->success(message: 'Dispositivo MFA vinculado y verificado exitosamente.');
        }

        Log::debug('MfaController: Verificación fallida de llave MFA (código incorrecto)', [
            'mfa_method_id' => $request->mfa_method_id,
        ]);

        return $this->error('Código incorrecto. Intenta de nuevo.', 400);
    }
}
