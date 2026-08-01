<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\ThirdFactorMail;
use App\Models\LoginAttempt;
use App\Models\MfaMethod;
use App\Models\MfaType;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

final class MfaService
{
    /**
     * Verifica la validez de un código MFA y procesa el paso siguiente (3FA o login final).
     *
     * @param array{mfa_method_id: string, code: string} $data
     * @return array<string, mixed>
     */
    public function verify(array $data): array
    {
        Log::debug('MfaService: Iniciando validación de código de segundo factor', [
            'mfa_method_id' => $data['mfa_method_id'],
        ]);

        /** @var MfaMethod|null $mfaMethod */
        $mfaMethod = MfaMethod::query()->with(['user.role'])->find($data['mfa_method_id']);

        if (! $mfaMethod) {
            Log::debug('MfaService: Método MFA no encontrado en la base de datos', [
                'mfa_method_id' => $data['mfa_method_id'],
            ]);

            return [
                'success' => false,
                'message' => 'Hubo un problema con la configuración de tu seguridad. Por favor, contacta a soporte técnico.',
                'code' => 400,
            ];
        }

        $google2fa = new Google2FA();
        $isValid = $google2fa->verifyKey($mfaMethod->secret, $data['code']);

        if (! $isValid) {
            Log::debug('MfaService: Código MFA incorrecto ingresado por el usuario', [
                'mfa_method_id' => $data['mfa_method_id'],
                'user_id' => $mfaMethod->user_id,
            ]);

            LoginAttempt::record($mfaMethod->user_id, $mfaMethod->user->email, 'failed_mfa', 2, 'Código de Authenticator App incorrecto.');

            return [
                'success' => false,
                'message' => 'El código de la App es incorrecto.',
                'code' => 401,
            ];
        }

        if (! $mfaMethod->is_verified) {
            Log::debug('MfaService: Marcando método de MFA como verificado', [
                'mfa_method_id' => $mfaMethod->id,
            ]);

            $mfaMethod->is_verified = true;
            $mfaMethod->save();
        }

        $user = $mfaMethod->user;
        $factorCount = $user->role?->factor_count ?? 2;

        Log::debug('MfaService: Código MFA validado correctamente. Evaluando necesidad de tercer factor', [
            'user_id' => $user->id,
            'factor_count' => $factorCount,
        ]);

        if ($factorCount > 2) {
            Log::debug('MfaService: Generando y enviando código OTP para el tercer factor', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            $otpCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put('email_otp_' . $user->id, $otpCode, 300);

            try {
                Mail::to($user->email)->send(new ThirdFactorMail($otpCode));
            } catch (\Throwable $e) {
                Log::error('MfaService: Error al enviar email de 3er factor', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                LoginAttempt::record($user->id, $user->email, 'failed_mfa_mail_error', 2, 'Error enviando correo de tercer factor: ' . $e->getMessage());

                return [
                    'success' => false,
                    'message' => 'Ocurrió un error en el servidor al intentar enviar el código a su correo electrónico. Por favor, verifique su dirección o intente más tarde.',
                    'code' => 500,
                ];
            }

            LoginAttempt::record($user->id, $user->email, 'success_factor_2', 2, 'Segundo factor exitoso. Requiere correo.');

            return [
                'success' => true,
                'requires_email_otp' => true,
                'user_id' => $user->id,
                'message' => 'Por favor revisa tu correo. Hemos enviado un código de 6 dígitos.',
                'code' => 200,
            ];
        }

        Log::debug('MfaService: Segundo factor completado exitosamente. Generando token Sanctum', [
            'user_id' => $user->id,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        LoginAttempt::record($user->id, $user->email, 'success_factor_2', 2, 'Segundo factor exitoso. Autenticado.');

        return [
            'success' => true,
            'message' => 'Verificación de Segundo Factor exitosa.',
            'user' => $user->load('role'),
            'token' => $token,
            'code' => 200,
        ];
    }

    /**
     * Genera los parámetros de configuración iniciales para registrar Google Authenticator.
     *
     * @return array{email: string, secretKey: string, qrCodeUrl: string, qrSvg: string, mfa_method_id: string}
     */
    public function generateSetupData(string $email): array
    {
        Log::debug('MfaService: Iniciando generación de datos para configurar MFA', [
            'email' => $email,
        ]);

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();

        /** @var MfaType $totpMfa */
        $totpMfa = MfaType::query()->firstOrCreate(
            ['type' => 'totp'],
            ['name' => 'Authenticator App', 'is_active' => true]
        );

        $google2fa = new Google2FA();
        $existingMethod = MfaMethod::query()
            ->where('user_id', $user->id)
            ->where('mfa_type_id', $totpMfa->id)
            ->first();

        // Si ya existe un método verificado, mantenemos o actualizamos la clave para permitir vinculación limpia
        $secretKey = ($existingMethod && $existingMethod->secret) ? $existingMethod->secret : $google2fa->generateSecretKey();

        /** @var MfaMethod $method */
        $method = MfaMethod::query()->updateOrCreate(
            ['user_id' => $user->id, 'mfa_type_id' => $totpMfa->id],
            [
                'secret' => $secretKey,
                'factor_step' => 2,
                'is_verified' => $existingMethod ? $existingMethod->is_verified : false,
                'is_active' => true,
            ]
        );

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            'Sistema MisVales',
            $user->email,
            $secretKey
        );

        $renderer = new ImageRenderer(
            new RendererStyle(250),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrSvg = $writer->writeString($qrCodeUrl);

        Log::debug('MfaService: Datos de QR y llave secreta generados correctamente', [
            'email' => $email,
            'mfa_method_id' => $method->id,
        ]);

        return [
            'email' => $user->email,
            'secretKey' => $secretKey,
            'qrCodeUrl' => $qrCodeUrl,
            'qrSvg' => $qrSvg,
            'mfa_method_id' => (string) $method->id,
        ];
    }
}
