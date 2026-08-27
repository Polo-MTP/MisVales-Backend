<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Catálogo global de códigos de error de la API: un identificador estable en inglés que un
 * cliente (frontend, integración externa) puede usar para distinguir el TIPO de error sin
 * tener que parsear el 'message' en español (que puede cambiar de texto sin previo aviso).
 * Viaja en el JSON de cualquier respuesta {success: false, ...} bajo la clave 'error_code'
 * (ver ApiResponse::error() y los renderers de bootstrap/app.php).
 *
 * No es un código por regla de negocio (ej. "CREDIT_LIMIT_EXCEEDED") -- es el nivel de
 * "mecanismo de falla" del framework/capa de seguridad: por qué HTTP-status-wise algo
 * truena, no el detalle fino de negocio (ese sigue viviendo en 'message').
 */
enum ApiErrorCode: string
{
    // Autenticación / sesión
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';

    // Autorización
    case FORBIDDEN = 'FORBIDDEN';
    case VPN_REQUIRED = 'VPN_REQUIRED';

    // Solicitud / validación
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case DOMAIN_ERROR = 'DOMAIN_ERROR';
    case NOT_FOUND = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case RATE_LIMITED = 'RATE_LIMITED';

    // Servidor
    case SERVER_ERROR = 'SERVER_ERROR';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /**
     * Respaldo por status HTTP para cualquier error que no traiga su propio código explícito
     * (ej. un abort(409, '...') suelto en algún service). No sustituye asignar el código
     * correcto a mano cuando se sabe cuál es -- es solo para que NINGUNA respuesta de error
     * se quede sin 'error_code'.
     */
    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 401 => self::UNAUTHENTICATED,
            $status === 403 => self::FORBIDDEN,
            $status === 404 => self::NOT_FOUND,
            $status === 405 => self::METHOD_NOT_ALLOWED,
            $status === 422 => self::VALIDATION_ERROR,
            $status === 429 => self::RATE_LIMITED,
            $status === 503 => self::SERVICE_UNAVAILABLE,
            $status >= 400 && $status < 500 => self::DOMAIN_ERROR,
            default => self::SERVER_ERROR,
        };
    }
}
