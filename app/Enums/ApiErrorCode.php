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
    case SESSION_IDLE_TIMEOUT = 'SESSION_IDLE_TIMEOUT';
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

    /**
     * Código corto para mostrar al usuario junto al mensaje (ej. "Código: MV-301"). NO es lo
     * mismo que $this->value -- ese es el identificador técnico en inglés para que el
     * frontend distinga el tipo de error sin parsear 'message'; este es para que la persona
     * lo lea, lo anote y lo reporte a soporte, sin exponer nada técnico (nombre de excepción,
     * status HTTP crudo, stack trace). AppendSupportCode lo agrega solo, en cada respuesta de
     * error -- no hace falta tocar cada controller/middleware que arma un error a mano.
     *
     * Agrupado por prefijo para que soporte pueda triar por familia con solo ver el número:
     * 1xx sesión, 2xx permisos, 3xx solicitud/negocio, 5xx servidor.
     */
    public function supportCode(): string
    {
        return match ($this) {
            self::UNAUTHENTICATED => 'MV-101',
            self::SESSION_IDLE_TIMEOUT => 'MV-102',
            self::ACCOUNT_INACTIVE => 'MV-103',
            self::FORBIDDEN => 'MV-201',
            self::VPN_REQUIRED => 'MV-202',
            self::VALIDATION_ERROR => 'MV-301',
            self::DOMAIN_ERROR => 'MV-302',
            self::NOT_FOUND => 'MV-303',
            self::METHOD_NOT_ALLOWED => 'MV-304',
            self::RATE_LIMITED => 'MV-305',
            self::SERVER_ERROR => 'MV-501',
            self::SERVICE_UNAVAILABLE => 'MV-502',
        };
    }
}
