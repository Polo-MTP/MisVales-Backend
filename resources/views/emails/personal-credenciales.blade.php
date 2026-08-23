<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tu cuenta en Mis Vales</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="color: #1f2937; margin-top: 0;">Bienvenido(a) a Mis Vales</h2>
        <p style="color: #4b5563; line-height: 1.5;">Se creó una cuenta de <strong>{{ $rol }}</strong> para ti, {{ $nombre }}. Estos son tus datos de acceso:</p>

        <table style="width: 100%; margin: 20px 0; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; color: #6b7280; font-size: 14px;">Correo</td>
                <td style="padding: 8px 0; color: #1f2937; font-weight: bold;">{{ $email }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280; font-size: 14px;">Contraseña temporal</td>
                <td style="padding: 8px 0;">
                    <span style="display: inline-block; font-size: 18px; font-weight: bold; letter-spacing: 1px; color: #2563eb; background: #eff6ff; padding: 8px 14px; border-radius: 6px; border: 1px solid #bfdbfe;">
                        {{ $password }}
                    </span>
                </td>
            </tr>
        </table>

        <p style="color: #6b7280; font-size: 14px;">Por tu seguridad, inicia sesión y cambia esta contraseña lo antes posible. Si tú no esperabas este correo, avisa a tu gerente de inmediato.</p>
    </div>
</body>
</html>
