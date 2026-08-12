<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Código de Verificación - Tercer Factor</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="color: #1f2937; margin-top: 0;">Código de Verificación de Seguridad</h2>
        <p style="color: #4b5563; line-height: 1.5;">Has iniciado sesión como {{ $rol }}. Para completar el acceso, ingresa el siguiente código OTP de 6 dígitos:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <span style="display: inline-block; font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #2563eb; background: #eff6ff; padding: 12px 24px; border-radius: 6px; border: 1px solid #bfdbfe;">
                {{ $code }}
            </span>
        </div>

        <p style="color: #6b7280; font-size: 14px;">Este código es válido únicamente durante 5 minutos. Si no has solicitado este acceso, te sugerimos cambiar tu contraseña de inmediato.</p>
    </div>
</body>
</html>
