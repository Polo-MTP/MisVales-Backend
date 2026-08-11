<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AltaProveedor\SolicitudProveedorController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Distribuidora\CategoriaDistribuidoraController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\Producto\ProductoController;
use App\Http\Controllers\Api\V1\Relacion\ConciliacionController;
use App\Http\Controllers\Api\V1\Relacion\RelacionController;
use App\Http\Controllers\Api\V1\Reporte\ReporteController;
use App\Http\Controllers\Api\V1\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Routes for API version 1.
|
*/

// Public routes with auth rate limiter (5/min - brute force protection)
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->name('api.v1.login');

    // MFA & 3FA verification & setup
    Route::post('mfa/verify', [MfaController::class, 'verify'])->name('api.v1.mfa.verify');
    Route::post('mfa/email/verify', [MfaController::class, 'verifyEmailOtp'])->name('api.v1.mfa.email.verify');
    Route::get('mfa/setup', [MfaController::class, 'showSetup'])->name('api.v1.mfa.setup');
    Route::post('mfa/setup/confirm', [MfaController::class, 'confirmSetup'])->name('api.v1.mfa.setup.confirm');
});

// Protected routes for active authenticated users (120/min)
Route::middleware(['auth:sanctum', 'active', 'throttle:authenticated'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');

    // Email verification
    Route::post('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Listado de usuarios (ej. verificadores disponibles para asignar en alta-proveedor)
    Route::get('usuarios', [UsuarioController::class, 'index'])
        ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
        ->name('api.v1.usuarios.index');

    // MÓDULO 1: ALTA DE PROVEEDORES (NUEVO DISTRIBUIDOR)
    Route::prefix('alta-proveedor')->group(function (): void {
        Route::get('solicitudes', [SolicitudProveedorController::class, 'index'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.alta_proveedor.index');

        Route::get('solicitudes/{solicitud}', [SolicitudProveedorController::class, 'show'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.alta_proveedor.show');

        Route::post('solicitudes', [SolicitudProveedorController::class, 'store'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.store');

        Route::post('solicitudes/{solicitud}/verificar', [SolicitudProveedorController::class, 'verificar'])
            ->middleware('role:Verificador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.verificar');

        Route::post('solicitudes/{solicitud}/aprobar', [SolicitudProveedorController::class, 'aprobarORechazar'])
            ->middleware('role:Gerente de Sucursal,Gerente General')
            ->name('api.v1.alta_proveedor.aprobar');
    });

    // MÓDULO 2: GESTIÓN DE CLIENTES DE DISTRIBUIDORA
    Route::prefix('distribuidora')->group(function (): void {
        Route::get('perfil', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'miPerfil'])
            ->middleware('role:Distribuidora,Gerente General,Administrador')
            ->name('api.v1.distribuidora.perfil');

        Route::get('clientes', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'index'])
            ->middleware('role:Distribuidora,Gerente General,Administrador,Gerente de Sucursal')
            ->name('api.v1.distribuidora.clientes.index');

        Route::post('clientes', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'store'])
            ->middleware('role:Distribuidora,Gerente General,Administrador')
            ->name('api.v1.distribuidora.clientes.store');

        Route::get('clientes/{id}', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'show'])
            ->middleware('role:Distribuidora,Gerente General,Administrador,Gerente de Sucursal')
            ->name('api.v1.distribuidora.clientes.show');

        Route::put('clientes/{id}', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'update'])
            ->middleware('role:Distribuidora,Gerente General,Administrador')
            ->name('api.v1.distribuidora.clientes.update');

        Route::patch('clientes/{id}/estado', [App\Http\Controllers\Api\V1\Distribuidora\ClienteController::class, 'cambiarEstado'])
            ->middleware('role:Distribuidora,Gerente General,Administrador')
            ->name('api.v1.distribuidora.clientes.estado');
    });

    // MÓDULO 3: CONFIGURACIONES (REGLAS DE NEGOCIO Y FECHAS POR VIGENCIA)
    Route::prefix('configuraciones')->group(function (): void {
        Route::get('', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'index'])
            ->middleware('role:Administrador,Gerente General,Gerente de Sucursal')
            ->name('api.v1.configuraciones.index');

        Route::post('', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'store'])
            ->middleware('role:Administrador,Gerente General')
            ->name('api.v1.configuraciones.store');

        Route::get('historial/{clave}', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'historial'])
            ->middleware('role:Administrador,Gerente General')
            ->name('api.v1.configuraciones.historial');

        Route::get('fechas', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'fechasIndex'])
            ->middleware('role:Administrador,Gerente General,Gerente de Sucursal')
            ->name('api.v1.configuraciones.fechas.index');

        Route::post('fechas', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'fechasStore'])
            ->middleware('role:Administrador,Gerente General')
            ->name('api.v1.configuraciones.fechas.store');

        Route::get('fechas/historial', [App\Http\Controllers\Api\V1\Configuracion\ConfiguracionController::class, 'fechasHistorial'])
            ->middleware('role:Administrador,Gerente General')
            ->name('api.v1.configuraciones.fechas.historial');
    });

    // MÓDULO 4: AUDITORÍA DE CAMBIOS DE ESTADO DE DISTRIBUIDORAS
    // El cambio de estado en sí se hace vía MÓDULO 6 (PUT distribuidoras/{id}/estado),
    // que tiene autorización granular por estado destino.
    Route::prefix('distribuidoras')->group(function (): void {
        Route::get('{id}/historial-estado', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraEstadoController::class, 'historial'])
            ->middleware('role:Gerente General,Administrador,Gerente de Sucursal,Distribuidora')
            ->name('api.v1.distribuidoras.estado.historial');
    });
    // ============================================================
    // MÓDULO 5: CATÁLOGO DE PRODUCTOS (solo Gerente General escribe; Administrador solo lectura)
    // ============================================================
    Route::prefix('productos')
        ->group(function () {
            Route::get('/', [ProductoController::class, 'index'])
                ->name('api.v1.productos.index');
            Route::get('{producto}', [ProductoController::class, 'show'])
                ->middleware('role:Administrador,Gerente General')
                ->name('api.v1.productos.show');
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

    // Catálogo de categorías de distribuidora (usado por el selector de PUT distribuidoras/{id}/credito)
    Route::get('categorias-distribuidoras', [CategoriaDistribuidoraController::class, 'index'])
        ->middleware('role:Gerente de Sucursal,Gerente General,Administrador')
        ->name('api.v1.categorias_distribuidoras.index');

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
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.distribuidoras.index');

        // Detalle, actualización y eliminación (con autorización por política)
        Route::get('{distribuidora}', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'show'])
            ->middleware('role:Coordinador,Verificador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.distribuidoras.show');
        Route::put('{distribuidora}', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'update'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.update');
        Route::delete('{distribuidora}', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'destroy'])
            ->middleware('role:Gerente General')
            ->name('api.v1.distribuidoras.destroy');

        // Acciones específicas
        Route::put('{distribuidora}/estado', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'cambiarEstado'])
            ->middleware('role:Verificador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.estado');
        Route::put('{distribuidora}/credito', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'asignarCredito'])
            ->middleware('role:Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.credito');
        Route::get('{distribuidora}/saldo-disponible', [App\Http\Controllers\Api\V1\Distribuidora\DistribuidoraController::class, 'saldoDisponible'])
            ->middleware('role:Cajera,Distribuidora,Gerente de Sucursal,Gerente General')
            ->name('api.v1.distribuidoras.saldo');
    });

    // ============================================================
    // MÓDULO 7: RELACIÓN DE CÁLCULOS (cortes / estado de cuenta por distribuidora)
    // ============================================================
    Route::prefix('relaciones')->group(function (): void {
        Route::get('/', [RelacionController::class, 'index'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.relaciones.index');

        Route::get('{relacion}', [RelacionController::class, 'show'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.relaciones.show');

        // Disparo manual del corte (el disparo normal es automático vía comando programado). Administrador
        // queda fuera a propósito: solo lee, no genera ni autoriza nada.
        Route::post('generar', [RelacionController::class, 'generar'])
            ->middleware('role:Gerente General')
            ->name('api.v1.relaciones.generar');

        Route::post('{relacion}/perdonar', [RelacionController::class, 'perdonar'])
            ->middleware('role:Gerente de Sucursal,Gerente General')
            ->name('api.v1.relaciones.perdonar');
    });

    // ============================================================
    // MÓDULO 8: CONCILIACIÓN BANCARIA (importación del Excel del banco)
    // ============================================================
    Route::prefix('conciliaciones')->group(function (): void {
        Route::get('/', [ConciliacionController::class, 'index'])
            ->middleware('role:Cajera,Coordinador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.conciliaciones.index');

        Route::post('importar', [ConciliacionController::class, 'importar'])
            ->middleware('role:Cajera,Gerente de Sucursal,Gerente General')
            ->name('api.v1.conciliaciones.importar');

        // Autorización de conciliación manual cuando la referencia no coincidió con ninguna relación.
        Route::post('{abono}/conciliar-manual', [ConciliacionController::class, 'conciliarManual'])
            ->middleware('role:Coordinador,Gerente de Sucursal,Gerente General')
            ->name('api.v1.conciliaciones.conciliar_manual');
    });

    // ============================================================
    // MÓDULO 9: REPORTES
    // ============================================================
    Route::prefix('reportes')->group(function (): void {
        Route::get('morosos', [ReporteController::class, 'morosos'])
            ->middleware('role:Cajera,Coordinador,Gerente de Sucursal,Gerente General,Administrador')
            ->name('api.v1.reportes.morosos');
    });

});

// Admin-only protected routes
Route::middleware(['auth:sanctum', 'active', 'role:Administrador', 'throttle:authenticated'])->group(function (): void {
    Route::get('admin/historical-data', [AuditController::class, 'getHistoricalData'])
        ->name('api.v1.admin.historical_data');
});

// Password reset routes (public with rate limiting)
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->name('password.email');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->name('password.reset');
});
