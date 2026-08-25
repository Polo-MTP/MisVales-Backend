<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AltaProveedor\SolicitudProveedorController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Configuracion\SeguroTablaController;
use App\Http\Controllers\Api\V1\Distribuidora\CategoriaDistribuidoraController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\Producto\ProductoController;
use App\Http\Controllers\Api\V1\Relacion\ConciliacionController;
use App\Http\Controllers\Api\V1\Relacion\RelacionController;
use App\Http\Controllers\Api\V1\Reporte\ReporteController;
use App\Http\Controllers\Api\V1\Sucursal\SucursalController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UsuarioController;
use App\Http\Controllers\Api\V1\Vale\ValeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Routes for API version 1.
|
*/

// Public status endpoint (server identification - no auth, no rate limit)
Route::get('status', function () {
    return response()->json([
        'server' => config('app.server_number', '?'),
    ]);
})->name('api.v1.status');

// Public routes with auth rate limiter (5/min - brute force protection)
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->name('api.v1.login');

    // MFA & 3FA verification & setup
    Route::post('mfa/verify', [MfaController::class, 'verify'])->name('api.v1.mfa.verify');
    Route::post('mfa/email/verify', [MfaController::class, 'verifyEmailOtp'])->name('api.v1.mfa.email.verify');
    Route::get('mfa/setup', [MfaController::class, 'showSetup'])->middleware('signed')->name('api.v1.mfa.setup');
    Route::post('mfa/setup/confirm', [MfaController::class, 'confirmSetup'])->name('api.v1.mfa.setup.confirm');
});

// Protected routes for active authenticated users (120/min)
Route::middleware(['auth:sanctum', 'idle', 'active', 'throttle:authenticated'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');
    Route::put('me/password', [AuthController::class, 'changePassword'])->name('api.v1.me.password');
    Route::get('upload-url', [UploadController::class, 'getPresignedUrl'])->name('api.v1.upload_url');
    Route::get('read-url', [UploadController::class, 'getPresignedReadUrl'])->name('api.v1.read_url');

    // Email verification
    Route::post('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Listado de usuarios (ej. verificadores disponibles para asignar en alta-proveedor). El
    // controller ya contempla a Administrador ("ve todas las sucursales", igual que Gerente
    // General) pero el middleware no lo dejaba pasar -- lo necesita para poder filtrar la
    // bitácora de auditoría por usuario eligiéndolo por nombre, no adivinando su ID.
    Route::get('usuarios', [UsuarioController::class, 'index'])
        ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
        ->name('api.v1.usuarios.index');

    // Feed de "lo que yo he autorizado" -- sin restricción de rol, siempre es sobre uno mismo.
    Route::get('usuarios/mis-autorizaciones', [UsuarioController::class, 'misAutorizaciones'])
        ->name('api.v1.usuarios.mis_autorizaciones');

    // Solo da de alta Gerente de Sucursal -- el rol queda fijo en el controller, no lo manda
    // quien hace la petición. Confirmado con el equipo: dar de alta cuentas de staff no debe
    // exigir VPN, a diferencia de las decisiones de autorización (aprobar/rechazar) -- se
    // necesita poder crear cuentas también desde la red pública.
    Route::post('usuarios/gerente-sucursal', [UsuarioController::class, 'crearGerenteSucursal'])
        ->middleware('role:Gerente General')
        ->name('api.v1.usuarios.crear_gerente_sucursal');

    // Da de alta Administrador -- solo Gerente General. Un Administrador ve todo el sistema
    // sin acotar por sucursal (igual que Gerente General), así que dejar que un Gerente de
    // Sucursal lo diera de alta sería escalar su propio alcance más allá de su sucursal.
    Route::post('usuarios/administrador', [UsuarioController::class, 'crearAdministrador'])
        ->middleware('role:Gerente General')
        ->name('api.v1.usuarios.crear_administrador');

    // Da de alta Gerente General -- Administrador (para poder arrancar/reponer la cadena de
    // mando) y Gerente General (para poder dar de alta cualquier rol de staff, incluido el
    // suyo propio). Gerente de Sucursal NO puede: sería escalar su propio alcance.
    Route::post('usuarios/gerente-general', [UsuarioController::class, 'crearGerenteGeneral'])
        ->middleware('role:Administrador,Gerente General')
        ->name('api.v1.usuarios.crear_gerente_general');

    // Da de alta Coordinador, Verificador o Cajera -- el rol viene restringido a esas 3
    // opciones desde CrearPersonalSucursalRequest. Gerente de Sucursal solo puede darlos de
    // alta en su propia sucursal, relacionados a sí mismo; Gerente General puede asignar
    // cualquier sucursal + Gerente de Sucursal válido.
    Route::post('usuarios/personal', [UsuarioController::class, 'crearPersonalSucursal'])
        ->middleware('role:Gerente General,Gerente de Sucursal')
        ->name('api.v1.usuarios.crear_personal_sucursal');

    // Mueve todo el personal de un Gerente de Sucursal a otro (mismo patrón que
    // distribuidoras/reasignar-coordinador) -- típico cuando el de origen deja la empresa.
    // Solo Gerente General, como el resto de altas/bajas de Gerente de Sucursal.
    Route::post('usuarios/reasignar-gerente', [UsuarioController::class, 'reasignarPersonal'])
        ->middleware(['role:Gerente General', 'vpn'])
        ->name('api.v1.usuarios.reasignar_personal');

    // MÓDULO: SUCURSALES. Alta/edición solo Gerente General; listar/ver es de cualquier
    // usuario autenticado (lo necesitan varios selectores: crear Gerente de Sucursal, etc.).
    Route::prefix('sucursales')->group(function (): void {
        Route::get('/', [SucursalController::class, 'index'])
            ->name('api.v1.sucursales.index');
        Route::get('{sucursal}', [SucursalController::class, 'show'])
            ->name('api.v1.sucursales.show');
        Route::post('/', [SucursalController::class, 'store'])
            ->middleware('role:Gerente General')
            ->name('api.v1.sucursales.store');
        Route::put('{sucursal}', [SucursalController::class, 'update'])
            ->middleware('role:Gerente General')
            ->name('api.v1.sucursales.update');
    });

    // MÓDULO 1: ALTA DE PROVEEDORES (NUEVO DISTRIBUIDOR)
    Route::prefix('alta-proveedor')->group(function (): void {
        Route::get('solicitudes', [SolicitudProveedorController::class, 'index'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.index');

        Route::get('solicitudes/{solicitud}', [SolicitudProveedorController::class, 'show'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.show');

        Route::post('solicitudes', [SolicitudProveedorController::class, 'store'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.store');

        // Dictamen de verificación y decisión de gerencia: ambos son parte de la cadena de
        // autorización del alta (igual que las demás decisiones de autorización de la API,
        // solo deben contestar desde la red interna/VPN).
        Route::post('solicitudes/{solicitud}/verificar', [SolicitudProveedorController::class, 'verificar'])
            ->middleware(['role:Verificador,Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.alta_proveedor.verificar');

        Route::post('solicitudes/{solicitud}/aprobar', [SolicitudProveedorController::class, 'aprobarORechazar'])
            ->middleware(['role:Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.alta_proveedor.aprobar');

        // Subida real del archivo de una evidencia (logo, fachada, identificación, etc.).
        Route::post('solicitudes/{solicitud}/evidencias', [App\Http\Controllers\Api\V1\AltaProveedor\EvidenciaController::class, 'store'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.evidencias.store');
    });

    // MÓDULO 2: GESTIÓN DE CLIENTES DE DISTRIBUIDORA
    Route::prefix('distribuidora')->group(function (): void {
        Route::get('perfil', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'miPerfil'])
            ->middleware('role:Distribuidora,Gerente General')
            ->name('api.v1.distribuidora.perfil');

        Route::get('clientes', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'index'])
            ->middleware('role:Distribuidora,Gerente General,Gerente de Sucursal,Cajera')
            ->name('api.v1.distribuidora.clientes.index');

        Route::post('clientes', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'store'])
            ->middleware('role:Distribuidora,Gerente General')
            ->name('api.v1.distribuidora.clientes.store');

        // Debe ir ANTES de clientes/{id}: si no, el wildcard {id} capturaría "ediciones" como si
        // fuera un id y esta ruta nunca se alcanzaría. Listado de solicitudes de edición: sin esto
        // Coordinador/Gerente no tienen forma de ver qué hay pendiente de aprobar.
        Route::get('clientes/ediciones', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudEdicionClienteController::class, 'index'])
            ->middleware('role:Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidora.clientes.ediciones.index');

        // Misma razón: debe ir ANTES de clientes/{id}, si no el wildcard se comería
        // "transferencias" como si fuera un id de cliente.
        Route::get('clientes/transferencias', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudTransferenciaClienteController::class, 'index'])
            ->middleware('role:Distribuidora,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidora.clientes.transferencias.index');

        // Búsqueda por CURP exacta fuera de la cartera propia — para poder solicitar la
        // transferencia de un cliente de otra distribuidora. Misma razón de orden que arriba.
        Route::get('clientes/buscar-por-curp', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'buscarPorCurp'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.distribuidora.clientes.buscar_por_curp');

        Route::get('clientes/{id}', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'show'])
            ->middleware('role:Distribuidora,Gerente General,Gerente de Sucursal,Cajera')
            ->name('api.v1.distribuidora.clientes.show');

        Route::put('clientes/{id}', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'update'])
            ->middleware('role:Distribuidora,Gerente General')
            ->name('api.v1.distribuidora.clientes.update');

        Route::patch('clientes/{id}/estado', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'cambiarEstado'])
            ->middleware('role:Distribuidora,Gerente General')
            ->name('api.v1.distribuidora.clientes.estado');

        // La cajera comprueba los datos del pre-vale/vale del cliente; si algo necesita corrección,
        // pide autorización a Coordinador/Gerente de Sucursal/Gerente General antes de poder editar.
        Route::post('clientes/{id}/solicitar-edicion', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudEdicionClienteController::class, 'store'])
            ->middleware('role:Cajera')
            ->name('api.v1.distribuidora.clientes.solicitar_edicion');

        // Endpoint de decisión (aprobar/rechazar) — confirmado por infra como uno de los
        // que solo debe contestar por VPN. La restricción real va en el firewall del
        // droplet; 'vpn' es la segunda capa (ver VerifyVpnAccess), no-op hasta que
        // VPN_HOST tenga el dominio real.
        Route::put('clientes/ediciones/{solicitud}/decidir', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudEdicionClienteController::class, 'decidir'])
            ->middleware(['role:Coordinador,Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidora.clientes.decidir_edicion');

        Route::put('clientes/{id}/editar-datos', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudEdicionClienteController::class, 'aplicar'])
            ->middleware('role:Cajera')
            ->name('api.v1.distribuidora.clientes.editar_datos');

        // Transferencia de un cliente entre distribuidoras: destino solicita -> coordinador/
        // gerente de la ORIGEN autoriza -> destino confirma (el listado va arriba, junto a
        // clientes/ediciones, por la colisión con el wildcard clientes/{id}).
        Route::post('clientes/{cliente}/solicitar-transferencia', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudTransferenciaClienteController::class, 'store'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.distribuidora.clientes.transferencias.store');

        // Autorización del coordinador/gerente de la distribuidora origen: es una decisión de
        // autorización, va con VPN igual que decidir_edicion.
        Route::put('clientes/transferencias/{solicitud}/decidir', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudTransferenciaClienteController::class, 'decidir'])
            ->middleware(['role:Coordinador,Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidora.clientes.transferencias.decidir');

        // Confirmación final de la propia distribuidora destino: autoservicio, sin VPN.
        Route::put('clientes/transferencias/{solicitud}/aceptar', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudTransferenciaClienteController::class, 'aceptar'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.distribuidora.clientes.transferencias.aceptar');
    });

    // MÓDULO 3: CONFIGURACIONES (REGLAS DE NEGOCIO Y FECHAS POR VIGENCIA)
    Route::prefix('configuraciones')->group(function (): void {
        Route::get('', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'index'])
            ->middleware('role:Gerente General,Gerente de Sucursal')
            ->name('api.v1.configuraciones.index');

        // Cambia parámetros de negocio (comisión, %quincena, multa) que aplican a TODOS los
        // vales que se generen de ahí en adelante — mayor radio de impacto que una decisión
        // puntual, mismo dueño (Gerente General) que ya exige VPN en el resto de la API.
        Route::post('', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'store'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.configuraciones.store');

        Route::get('historial/{clave}', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'historial'])
            ->middleware('role:Gerente General')
            ->name('api.v1.configuraciones.historial');

        Route::get('fechas', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'fechasIndex'])
            ->middleware('role:Gerente General,Gerente de Sucursal')
            ->name('api.v1.configuraciones.fechas.index');

        Route::post('fechas', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'fechasStore'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.configuraciones.fechas.store');

        Route::get('fechas/historial', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'fechasHistorial'])
            ->middleware('role:Gerente General')
            ->name('api.v1.configuraciones.fechas.historial');

        // Tabla de rangos de seguro por monto de vale ("varía según la cantidad") — la usa
        // RelacionCalculoService en vivo, sin esto solo se podía cambiar directo en BD.
        Route::get('seguros', [SeguroTablaController::class, 'index'])
            ->middleware('role:Gerente General,Gerente de Sucursal')
            ->name('api.v1.configuraciones.seguros.index');

        Route::post('seguros', [SeguroTablaController::class, 'store'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.configuraciones.seguros.store');

        Route::put('seguros/{seguro}', [SeguroTablaController::class, 'update'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.configuraciones.seguros.update');

        Route::delete('seguros/{seguro}', [SeguroTablaController::class, 'destroy'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.configuraciones.seguros.destroy');
    });

    // MÓDULO 4: AUDITORÍA DE CAMBIOS DE ESTADO DE DISTRIBUIDORAS
    // El cambio de estado en sí se hace vía MÓDULO 6 (PUT distribuidoras/{id}/estado),
    // que tiene autorización granular por estado destino.
    Route::prefix('distribuidoras')->group(function (): void {
        Route::get('{id}/historial-estado', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraEstadoController::class, 'historial'])
            ->middleware('role:Gerente General,Gerente de Sucursal,Distribuidora')
            ->name('api.v1.distribuidoras.estado.historial');
    });
    // ============================================================
    // MÓDULO 5: CATÁLOGO DE PRODUCTOS. CRUD completo solo Gerente General, sin VPN — no es
    // una decisión de autorización de un tercero como el resto de rutas con 'vpn'. Gerente
    // de Sucursal es de solo lectura (index/show), no puede crear, editar ni desactivar.
    // Administrador queda fuera, solo ve logs.
    // ============================================================
    Route::prefix('productos')
        ->group(function () {
            Route::get('/', [ProductoController::class, 'index'])
                ->name('api.v1.productos.index');
            Route::get('{producto}', [ProductoController::class, 'show'])
                ->middleware('role:Gerente General,Gerente de Sucursal')
                ->name('api.v1.productos.show');
            // Previsualización del pago quincenal estimado — sin restricción de rol, es
            // justo lo que la Distribuidora necesita ver antes de pedir el vale.
            Route::get('{producto}/simulacion', [ProductoController::class, 'simular'])
                ->name('api.v1.productos.simulacion');
            Route::post('/', [ProductoController::class, 'store'])
                ->middleware('role:Gerente General')
                ->name('api.v1.productos.store');
            Route::put('{producto}', [ProductoController::class, 'update'])
                ->middleware('role:Gerente General')
                ->name('api.v1.productos.update');
            Route::delete('{producto}', [ProductoController::class, 'destroy'])
                ->middleware('role:Gerente General')
                ->name('api.v1.productos.destroy');
        });

    // Catálogo de categorías de distribuidora (usado por el selector de PUT distribuidoras/{id}/credito
    // y por RelacionCalculoService al calcular el descuento de categoría en cada corte).
    Route::prefix('categorias-distribuidoras')->group(function (): void {
        Route::get('/', [CategoriaDistribuidoraController::class, 'index'])
            ->middleware('role:Gerente de Sucursal,Gerente General')
            ->name('api.v1.categorias_distribuidoras.index');

        Route::post('/', [CategoriaDistribuidoraController::class, 'store'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.categorias_distribuidoras.store');

        Route::put('{categoria}', [CategoriaDistribuidoraController::class, 'update'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.categorias_distribuidoras.update');

        Route::delete('{categoria}', [CategoriaDistribuidoraController::class, 'destroy'])
            ->middleware(['role:Gerente General', 'vpn'])
            ->name('api.v1.categorias_distribuidoras.destroy');
    });

    // ============================================================
    // MÓDULO 6: GESTIÓN AVANZADA DE DISTRIBUIDORAS
    // ============================================================
    Route::prefix('distribuidoras')->group(function () {
        // Las rutas que ya tienes (estado, historial) se mantienen.
        // Agregamos las nuevas rutas:

        // Listar distribuidoras (con filtros por rol en el servicio).
        // La creación de distribuidoras NO se hace aquí: el alta de un nuevo proveedor/distribuidor
        // se hace exclusivamente vía el flujo de solicitud (MÓDULO 1: alta-proveedor/solicitudes),
        // que exige captura -> verificación -> aprobación de gerencia antes de crear la distribuidora.
        Route::get('/', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'index'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Cajera')
            ->name('api.v1.distribuidoras.index');

        // Reasignación masiva de cartera de un coordinador a otro (p.ej. el coordinador deja
        // la sucursal/empresa). Debe ir ANTES de {distribuidora} para no ser capturada por el
        // wildcard. Es una decisión gerencial, no operación de rutina: va con VPN.
        Route::post('reasignar-coordinador', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'reasignarCoordinador'])
            ->middleware(['role:Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidoras.reasignar_coordinador');

        // Solicitud de aumento de crédito: la distribuidora (o su coordinador) pide, el
        // gerente decide el monto otorgado. Debe ir ANTES de {distribuidora} por la misma
        // razón que reasignar-coordinador: si no, el wildcard se comería "aumento-credito".
        Route::get('aumento-credito', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudAumentoCreditoController::class, 'index'])
            ->middleware('role:Distribuidora,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.aumento_credito.index');

        Route::post('{distribuidora}/aumento-credito', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudAumentoCreditoController::class, 'store'])
            ->middleware('role:Distribuidora,Coordinador')
            ->name('api.v1.distribuidoras.aumento_credito.store');

        // Decisión del gerente — decisión de autorización, va con VPN igual que el resto.
        Route::put('aumento-credito/{solicitud}/decidir', [App\Http\Controllers\Api\V1\Distribuidora\SolicitudAumentoCreditoController::class, 'decidir'])
            ->middleware(['role:Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidoras.aumento_credito.decidir');

        // Detalle, actualización y eliminación (con autorización por política)
        Route::get('{distribuidora}', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'show'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.show');
        Route::put('{distribuidora}', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'update'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.update');
        Route::delete('{distribuidora}', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'destroy'])
            ->middleware('role:Gerente General')
            ->name('api.v1.distribuidoras.destroy');

        // Acciones específicas — decisiones de autorización: solo VPN, igual que el resto.
        Route::put('{distribuidora}/estado', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'cambiarEstado'])
            ->middleware(['role:Verificador,Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidoras.estado');
        Route::put('{distribuidora}/credito', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'asignarCredito'])
            ->middleware(['role:Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidoras.credito');
        Route::get('{distribuidora}/saldo-disponible', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'saldoDisponible'])
            ->middleware('role:Cajera,Distribuidora,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.saldo');

        // Contrato firmado — parte del mismo acto administrativo que aprobar/asignar crédito.
        Route::post('{distribuidora}/contrato', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'subirContrato'])
            ->middleware(['role:Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.distribuidoras.contrato');

        // Reasignación masiva de cartera: el coordinador mueve TODOS los clientes de una
        // distribuidora suya a otra suya (típicamente cuando la de origen deja de operar).
        // No es una decisión de autorización de un tercero, es el propio coordinador operando
        // su cartera, por eso no lleva 'vpn'.
        Route::post('{distribuidora}/reasignar-clientes', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'reasignarTodos'])
            ->middleware('role:Coordinador')
            ->name('api.v1.distribuidoras.clientes.reasignar');

        // Historial de movimientos de puntos (generados, penalizados, canjeados, ajustes): sin
        // esto no había forma de ver cómo se llegó al saldo actual, solo el número final.
        Route::get('{distribuidora}/puntos', [App\Http\Controllers\Api\V1\Distribuidora\PuntoCanjeController::class, 'index'])
            ->middleware('role:Cajera,Distribuidora,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.puntos.index');

        // Canje de puntos: la cajera lo captura en caja.
        Route::post('{distribuidora}/puntos/canjear', [App\Http\Controllers\Api\V1\Distribuidora\PuntoCanjeController::class, 'canjear'])
            ->middleware('role:Cajera')
            ->name('api.v1.distribuidoras.puntos.canjear');
    });

    // ============================================================
    // MÓDULO 10: VALES (EMISIÓN) — prerequisito de Relaciones/Conciliación:
    // sin esto, RelacionCalculoService nunca tiene vales que cortar.
    // ============================================================
    Route::prefix('vales')->group(function (): void {
        Route::get('/', [ValeController::class, 'index'])
            ->middleware('role:Distribuidora,Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.vales.index');

        Route::post('/', [ValeController::class, 'store'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.vales.store');

        // La cajera valida en persona los datos del cliente contra el vale — paso obligatorio
        // antes de poder autorizar/pagar. Misma razón que autorizar: responde desde red pública.
        Route::put('{vale}/validar', [ValeController::class, 'validar'])
            ->middleware('role:Cajera')
            ->name('api.v1.vales.validar');

        // Solo la cajera autoriza/paga el vale al cliente en caja — ni Coordinador ni gerencia.
        // A diferencia de las demás decisiones de autorización, esta sí debe responder desde
        // la red pública (la cajera no opera desde la VPN interna). Requiere que ya esté 'validado'.
        Route::put('{vale}/autorizar', [ValeController::class, 'autorizar'])
            ->middleware('role:Cajera')
            ->name('api.v1.vales.autorizar');

        // La distribuidora activa/desactiva sus propios vales sin autorización de nadie más.
        Route::put('{vale}/desactivar', [ValeController::class, 'desactivar'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.vales.desactivar');

        Route::put('{vale}/activar', [ValeController::class, 'activar'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.vales.activar');
    });

    // ============================================================
    // MÓDULO 7: RELACIÓN DE CÁLCULOS (cortes / estado de cuenta por distribuidora)
    // ============================================================
    Route::prefix('relaciones')->group(function (): void {
        // Distribuidora incluida a propósito (igual que en conciliaciones): necesita ver sus
        // propios cortes para saber cuánto le toca pagar cada quincena y para cuándo.
        Route::get('/', [RelacionController::class, 'index'])
            ->middleware('role:Distribuidora,Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.relaciones.index');

        // Debe ir ANTES de {relacion}: si no, el wildcard capturaría "proximo-pago" como si
        // fuera un id. Cuándo será el próximo corte y cuánto se estima, antes de que exista.
        Route::get('proximo-pago', [RelacionController::class, 'proximoPago'])
            ->middleware('role:Distribuidora,Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.relaciones.proximo_pago');

        Route::get('{relacion}', [RelacionController::class, 'show'])
            ->middleware('role:Distribuidora,Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.relaciones.show');

        // Disparo manual del corte (el disparo normal es automático vía comando programado). Administrador
        // queda fuera a propósito: solo lee, no genera ni autoriza nada.
        Route::post('generar', [RelacionController::class, 'generar'])
            ->middleware('role:Gerente General')
            ->name('api.v1.relaciones.generar');

        // Decisión de autorización: solo VPN, igual que el resto.
        Route::post('{relacion}/perdonar', [RelacionController::class, 'perdonar'])
            ->middleware(['role:Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.relaciones.perdonar');
    });

    // ============================================================
    // MÓDULO 8: CONCILIACIÓN BANCARIA (importación del Excel del banco)
    // ============================================================
    Route::prefix('conciliaciones')->group(function (): void {
        // Distribuidora incluida a propósito: necesita ver sus propios abonos para poder
        // levantar una queja sobre uno (el controller la restringe a solo los suyos).
        Route::get('/', [ConciliacionController::class, 'index'])
            ->middleware('role:Distribuidora,Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.conciliaciones.index');

        Route::post('importar', [ConciliacionController::class, 'importar'])
            ->middleware('role:Cajera,Gerente de Sucursal,Gerente General')
            ->name('api.v1.conciliaciones.importar');

        // Listado de solicitudes de conciliación manual: sin esto Coordinador/Gerente no tienen
        // forma de ver qué hay pendiente de aprobar.
        Route::get('autorizaciones', [ConciliacionController::class, 'listarAutorizaciones'])
            ->middleware('role:Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.conciliaciones.autorizaciones.index');

        // Solo la cajera puede hacer conciliaciones manuales; para ello primero necesita que un
        // Coordinador/Gerente autorice la solicitud puntual sobre ese abono.
        Route::post('{abono}/solicitar-autorizacion', [ConciliacionController::class, 'solicitarAutorizacion'])
            ->middleware('role:Cajera')
            ->name('api.v1.conciliaciones.solicitar_autorizacion');

        // La distribuidora reporta que un abono no coincide con lo que ella pagó — solo
        // informativo, no dispara la corrección por sí solo (eso lo sigue haciendo la cajera).
        Route::post('{abono}/queja', [ConciliacionController::class, 'levantarQueja'])
            ->middleware('role:Distribuidora')
            ->name('api.v1.conciliaciones.queja');

        // Endpoint de decisión (aprobar/rechazar) — confirmado por infra como uno de los
        // que solo debe contestar por VPN. La restricción real va en el firewall del
        // droplet; 'vpn' es la segunda capa (ver VerifyVpnAccess), no-op hasta que
        // VPN_HOST tenga el dominio real.
        Route::put('autorizaciones/{solicitud}/decidir', [ConciliacionController::class, 'decidirAutorizacion'])
            ->middleware(['role:Coordinador,Gerente de Sucursal,Gerente General', 'vpn'])
            ->name('api.v1.conciliaciones.decidir_autorizacion');

        Route::post('{abono}/conciliar-manual', [ConciliacionController::class, 'conciliarManual'])
            ->middleware('role:Cajera')
            ->name('api.v1.conciliaciones.conciliar_manual');
    });

    // ============================================================
    // MÓDULO 9: REPORTES
    // ============================================================
    Route::prefix('reportes')->group(function (): void {
        Route::get('morosos', [ReporteController::class, 'morosos'])
            ->middleware('role:Cajera,Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.reportes.morosos');
    });

    // ============================================================
    // MÓDULO 11: NOTIFICACIONES. Gerente de Sucursal ve todas las de su sucursal (supervisión);
    // Gerente General y Administrador ven todas, sin filtrar. Distribuidora/Verificador/
    // Coordinador/Cajera solo ven las suyas (destinatario_id) -- antes no tenían acceso aquí.
    // ============================================================
    Route::get('notificaciones', [App\Http\Controllers\Api\V1\NotificacionController::class, 'index'])
        ->middleware('role:Distribuidora,Cajera,Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
        ->name('api.v1.notificaciones.index');

    Route::put('notificaciones/{notificacion}/leida', [App\Http\Controllers\Api\V1\NotificacionController::class, 'marcarLeida'])
        ->middleware('role:Distribuidora,Cajera,Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
        ->name('api.v1.notificaciones.marcar_leida');

});

// Admin-only protected routes — el Administrador solo ve logs de todo lo que se hace en el aplicativo.
Route::middleware(['auth:sanctum', 'idle', 'active', 'role:Administrador', 'throttle:authenticated'])->group(function (): void {
    Route::get('admin/historical-data', [AuditController::class, 'getHistoricalData'])
        ->name('api.v1.admin.historical_data');

    Route::get('admin/logs', [AuditController::class, 'getAuditLog'])
        ->name('api.v1.admin.logs');
});

// Password reset routes (public with rate limiting)
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->name('password.email');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->name('password.reset');
});
