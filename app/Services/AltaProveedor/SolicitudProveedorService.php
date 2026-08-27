<?php

declare(strict_types=1);

namespace App\Services\AltaProveedor;

use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\DistribuidorDatosExtras;
use App\Models\Evidencia;
use App\Models\HistorialCoordinador;
use App\Models\LogNuevoProveedor;
use App\Models\Role;
use App\Models\SolicitudProveedor;
use App\Models\User;
use App\Mail\PersonalCredencialesMail;
use App\Services\Notificacion\NotificacionService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class SolicitudProveedorService
{
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Captura la solicitud inicial de un nuevo proveedor realizada por un Coordinador.
     * Asigna automáticamente la sucursal del coordinador a la solicitud.
     *
     * @param  array<string, mixed>  $data
     */
    public function crearSolicitud(array $data, User $coordinador): SolicitudProveedor
    {
        Log::debug('SolicitudProveedorService: Iniciando creación de solicitud de nuevo proveedor', [
            'coordinador_id' => $coordinador->id,
            'sucursal_id' => $coordinador->sucursal_id,
            'curp' => $data['curp'],
        ]);

        return DB::transaction(function () use ($data, $coordinador): SolicitudProveedor {
            /** @var Direccion $direccion */
            $direccion = Direccion::query()->create([
                'calle' => $data['calle'],
                'colonia' => $data['colonia'],
                'numero_ext' => $data['numero_ext'],
                'numero_int' => $data['numero_int'] ?? null,
                'codigo_postal' => $data['codigo_postal'],
                'estado' => $data['estado'],
                'ciudad' => $data['ciudad'],
            ]);

            /** @var DatosPersonales $datosPersonales */
            $datosPersonales = DatosPersonales::query()->create([
                'nombre' => $data['nombre'],
                'apellido_paterno' => $data['apellido_paterno'],
                'apellido_materno' => $data['apellido_materno'] ?? null,
                'curp' => $data['curp'],
                'direccion_id' => $direccion->id,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'lugar_nacimiento' => $data['lugar_nacimiento'] ?? null,
            ]);

            /** @var SolicitudProveedor $solicitud */
            $solicitud = SolicitudProveedor::query()->create([
                'datos_id' => $datosPersonales->id,
                'sucursal_id' => $coordinador->sucursal_id,
                'coordinador_id' => $coordinador->id,
                'verificador_id' => $data['verificador_id'] ?? null,
                'estado' => isset($data['verificador_id']) ? 'en_verificacion' : 'pendiente_verificacion',
                'rfc' => $data['rfc'],
                'datos_familiares' => $data['datos_familiares'] ?? null,
                'datos_vehiculos' => $data['datos_vehiculos'] ?? null,
                'datos_vivienda' => $data['datos_vivienda'] ?? null,
                'referencia_laboral' => $data['referencia_laboral'] ?? null,
                'categoria_id' => $data['categoria_id'] ?? null,
            ]);

            // Auditoría inicial de creación
            LogNuevoProveedor::query()->create([
                'solicitud_id' => $solicitud->id,
                'entidad_tipo' => 'SolicitudProveedor',
                'entidad_id' => $solicitud->id,
                'campo' => 'estado',
                'valor_anterior' => null,
                'valor_nuevo' => $solicitud->estado,
                'modificado_por' => $coordinador->id,
                'fecha_hora' => now(),
                'dispositivo' => request()->header('User-Agent'),
                'accion' => 'creacion',
                'motivo' => 'Captura inicial realizada por coordinador.',
            ]);

            // Guardar evidencias iniciales si fueron enviadas
            if (! empty($data['evidencias']) && is_array($data['evidencias'])) {
                foreach ($data['evidencias'] as $ev) {
                    Evidencia::query()->create([
                        'solicitud_id' => $solicitud->id,
                        'entidad_tipo' => 'SolicitudProveedor',
                        'entidad_id' => $solicitud->id,
                        'tipo_documento' => $ev['tipo_documento'],
                        'url_archivo' => $ev['url_archivo'],
                        'subido_por' => $coordinador->id,
                        'fecha_subida' => now(),
                    ]);
                }
            }

            Log::debug('SolicitudProveedorService: Solicitud creada exitosamente', [
                'solicitud_id' => $solicitud->id,
                'sucursal_id' => $solicitud->sucursal_id,
            ]);

            return $solicitud->load(['datosPersonales.direccion', 'sucursal', 'coordinador', 'categoria', 'evidencias']);
        });
    }

    /**
     * Verificación física en campo realizada por un Verificador.
     * Si los datos fueron modificados respecto a lo capturado por el Coordinador, registra el audit log del antes y después.
     *
     * @param  array<string, mixed>  $data
     */
    public function verificarSolicitud(SolicitudProveedor $solicitud, array $data, User $verificador): SolicitudProveedor
    {
        // Validación de permisos por sucursal: Solo Gerente General o Administrador pueden verificar de otras sucursales.
        if ($verificador->role?->name !== 'Gerente General' && $verificador->role?->name !== 'Administrador' && $verificador->sucursal_id !== $solicitud->sucursal_id) {
            Log::warning('SolicitudProveedorService: Intento de verificación en otra sucursal denegado', [
                'verificador_id' => $verificador->id,
                'verificador_sucursal' => $verificador->sucursal_id,
                'solicitud_sucursal' => $solicitud->sucursal_id,
            ]);
            abort(403, 'Acceso Denegado. No tienes permisos para gestionar solicitudes pertenecientes a otra sucursal.');
        }

        Log::debug('SolicitudProveedorService: Iniciando proceso de verificación', [
            'solicitud_id' => $solicitud->id,
            'verificador_id' => $verificador->id,
            'cumple' => $data['cumple'],
        ]);

        return DB::transaction(function () use ($solicitud, $data, $verificador): SolicitudProveedor {
            // lockForUpdate() + re-checar el estado aquí adentro, no antes de la transacción --
            // sin esto, dos peticiones casi simultáneas (doble clic, o dos verificadores)
            // pasaban ambas cualquier chequeo hecho afuera y las dos alcanzaban a "verificar"
            // la misma solicitud, la segunda pisando el dictamen de la primera.
            /** @var SolicitudProveedor $solicitud */
            $solicitud = SolicitudProveedor::query()->whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            if (! in_array($solicitud->estado, ['pendiente_verificacion', 'en_verificacion'], true)) {
                throw new DomainException('Esta solicitud ya no está pendiente de verificación (estado actual: '.$solicitud->estado.').');
            }

            $solicitud->load(['datosPersonales.direccion']);
            $datosPersonales = $solicitud->datosPersonales;
            $direccion = $datosPersonales?->direccion;
            $dispositivo = $data['dispositivo'] ?? request()->header('User-Agent');
            $motivo = $data['motivo_edicion'] ?? 'Corrección realizada durante visita física.';

            // Auditoría y actualización de Datos Personales
            if (! empty($data['datos_personales']) && is_array($data['datos_personales']) && $datosPersonales) {
                foreach ($data['datos_personales'] as $campo => $nuevoValor) {
                    // 'fecha_nacimiento' está casteado a Carbon en el modelo -- comparar/loguear
                    // el objeto tal cual contra el string que manda el request siempre daría
                    // "cambió" (tipos distintos) y ensuciaría el log con un datetime completo en
                    // vez de solo la fecha. Se normaliza a string de fecha antes de comparar.
                    $valorActual = $datosPersonales->{$campo} instanceof Carbon
                        ? $datosPersonales->{$campo}->toDateString()
                        : $datosPersonales->{$campo};

                    if ($nuevoValor !== null && (string) $valorActual !== (string) $nuevoValor) {
                        LogNuevoProveedor::query()->create([
                            'solicitud_id' => $solicitud->id,
                            'entidad_tipo' => 'DatosPersonales',
                            'entidad_id' => $datosPersonales->id,
                            'campo' => $campo,
                            'valor_anterior' => (string) $valorActual,
                            'valor_nuevo' => (string) $nuevoValor,
                            'modificado_por' => $verificador->id,
                            'fecha_hora' => now(),
                            'dispositivo' => $dispositivo,
                            'accion' => 'edicion_verificador',
                            'motivo' => $motivo,
                        ]);
                        $datosPersonales->{$campo} = $nuevoValor;
                    }
                }

                $datosPersonales->save();
            }

            // Auditoría y actualización de Dirección
            if (! empty($data['direccion']) && is_array($data['direccion']) && $direccion) {
                foreach ($data['direccion'] as $campo => $nuevoValor) {
                    if ($nuevoValor !== null && $direccion->{$campo} !== $nuevoValor) {
                        LogNuevoProveedor::query()->create([
                            'solicitud_id' => $solicitud->id,
                            'entidad_tipo' => 'Direccion',
                            'entidad_id' => $direccion->id,
                            'campo' => $campo,
                            'valor_anterior' => (string) $direccion->{$campo},
                            'valor_nuevo' => (string) $nuevoValor,
                            'modificado_por' => $verificador->id,
                            'fecha_hora' => now(),
                            'dispositivo' => $dispositivo,
                            'accion' => 'edicion_verificador',
                            'motivo' => $motivo,
                        ]);
                        $direccion->{$campo} = $nuevoValor;
                    }
                }

                $direccion->save();
            }

            // Guardar nuevas evidencias físicas
            if (! empty($data['evidencias']) && is_array($data['evidencias'])) {
                foreach ($data['evidencias'] as $ev) {
                    Evidencia::query()->create([
                        'solicitud_id' => $solicitud->id,
                        'entidad_tipo' => 'SolicitudProveedor',
                        'entidad_id' => $solicitud->id,
                        'tipo_documento' => $ev['tipo_documento'],
                        'url_archivo' => $ev['url_archivo'],
                        'subido_por' => $verificador->id,
                        'fecha_subida' => now(),
                    ]);
                }
            }

            // Actualizar datos de verificación en la solicitud
            $solicitud->verificador_id = $verificador->id;
            $solicitud->cumple = (bool) $data['cumple'];
            $solicitud->comentario_verificador = $data['comentario_verificador'];
            $solicitud->fecha_verificacion = now();
            $solicitud->estado = $data['cumple'] ? 'verificado' : 'rechazado';
            $solicitud->save();

            Log::debug('SolicitudProveedorService: Verificación completada', [
                'solicitud_id' => $solicitud->id,
                'estado' => $solicitud->estado,
            ]);

            if ($solicitud->coordinador) {
                $this->notificacionService->crear(
                    $solicitud->coordinador,
                    $solicitud->cumple ? 'solicitud_verificada' : 'solicitud_rechazada_verificador',
                    'Solicitud '.$solicitud->nombre,
                    $verificador
                );
            }

            // Solo si "cumple": ya está lista para que Gerencia decida. Si el verificador la
            // rechazó no hay nada que Gerencia deba autorizar todavía.
            if ($solicitud->cumple) {
                $this->notificacionService->notificarRolEnSucursal(
                    'Gerente de Sucursal',
                    $solicitud->sucursal_id,
                    'solicitud_lista_para_autorizar',
                    'Solicitud '.$solicitud->nombre,
                    $verificador
                );
            }

            return $solicitud->load(['datosPersonales.direccion', 'sucursal', 'coordinador', 'verificador', 'categoria', 'evidencias', 'logs']);
        });
    }

    /**
     * Decision final tomada por un Gerente (Aprobar o Rechazar).
     * Gerente General puede aprobar cualquier sucursal.
     * Gerente de Sucursal solo puede aprobar solicitudes pertenecientes a su sucursal.
     * Si es aprobado, crea la cuenta de usuario Distribuidora asignada a esa sucursal.
     *
     * @param  array<string, mixed>  $data
     */
    public function aprobarORechazar(SolicitudProveedor $solicitud, array $data, User $gerente): SolicitudProveedor
    {
        // Validación de permisos por sucursal: Gerente de Sucursal solo gestiona su sucursal.
        if ($gerente->role?->name !== 'Gerente General' && $gerente->sucursal_id !== $solicitud->sucursal_id) {
            Log::warning('SolicitudProveedorService: Intento de aprobación en otra sucursal denegado', [
                'gerente_id' => $gerente->id,
                'gerente_sucursal' => $gerente->sucursal_id,
                'solicitud_sucursal' => $solicitud->sucursal_id,
            ]);
            abort(403, 'Acceso Denegado. Como Gerente de Sucursal solo puedes aprobar o rechazar solicitudes de tu propia sucursal.');
        }

        Log::debug('SolicitudProveedorService: Procesando decisión de Gerencia', [
            'solicitud_id' => $solicitud->id,
            'gerente_id' => $gerente->id,
            'decision' => $data['decision'],
        ]);

        // $distribuidoraUser/$passwordGenerada se llenan dentro de la transacción (solo si se
        // aprueba) y se leen después de que cierra, para mandar el correo FUERA de ella -- ver
        // la nota junto a Mail::to() más abajo.
        $distribuidoraUser = null;
        $passwordGenerada = null;

        $solicitudProcesada = DB::transaction(function () use ($solicitud, $data, $gerente, &$distribuidoraUser, &$passwordGenerada): SolicitudProveedor {
            // lockForUpdate() + re-checar el estado aquí adentro: sin esto, dos peticiones casi
            // simultáneas (doble clic, o dos gerentes) podían pasar ambas la validación de
            // permisos hecha afuera y las dos alcanzaban a "decidir" la misma solicitud -- una
            // reaprobación intentaba crear un SEGUNDO User+Distribuidora (el rfc único en
            // Distribuidora lo tumbaba, pero el correo de credenciales ya se había mandado antes
            // del rollback, para una cuenta que nunca llegó a existir). Solo se puede decidir
            // sobre una solicitud que el Verificador ya dictaminó que sí cumple.
            /** @var SolicitudProveedor $solicitud */
            $solicitud = SolicitudProveedor::query()->whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            if ($solicitud->estado !== 'verificado') {
                throw new DomainException('Solo se puede aprobar o rechazar una solicitud que ya fue verificada por el Verificador (estado actual: '.$solicitud->estado.').');
            }

            $solicitud->load('datosPersonales.direccion');
            $dispositivo = $data['dispositivo'] ?? request()->header('User-Agent');

            if ($data['decision'] === 'aprobado') {
                /** @var Role|null $distribuidoraRole */
                $distribuidoraRole = Role::query()->where('name', 'Distribuidora')->first();

                // La contraseña la genera el sistema (no la elige quien aprueba) y se manda por
                // correo al nuevo usuario -- mismo patrón que crearGerenteSucursal/
                // crearGerenteGeneral/crearPersonalSucursal en UsuarioController, para que nadie
                // más que la distribuidora llegue a conocerla.
                $passwordGenerada = Str::password(22);

                // Crear la cuenta de usuario para la distribuidora asignada a la sucursal de la solicitud
                $distribuidoraUser = User::query()->create([
                    'name' => $solicitud->datosPersonales->nombre.' '.$solicitud->datosPersonales->apellido_paterno,
                    'email' => $data['email'],
                    'password' => Hash::make($passwordGenerada),
                    'role_id' => $distribuidoraRole?->id,
                    'datos_id' => $solicitud->datos_id,
                    'sucursal_id' => $solicitud->sucursal_id,
                    'is_active' => true,
                ]);

                // 'email_verified_at' no está en $fillable de User a propósito -- mandarlo
                // dentro del create() de arriba se ignora en silencio y la cuenta queda sin
                // verificar. Se asigna aparte.
                $distribuidoraUser->email_verified_at = now();
                $distribuidoraUser->save();

                // El correo YA NO se manda aquí -- ver la nota junto a Mail::to() después de
                // que cierra la transacción, más abajo en este método.

                $limiteCredito = (float) $data['limite_credito_asignado'];
                /** @var Distribuidora $distribuidora */
                $distribuidora = Distribuidora::query()->create([
                    'usuario_id' => $distribuidoraUser->id,
                    'numero_distribuidora' => 'DIST-'.mb_str_pad((string) $distribuidoraUser->id, 5, '0', STR_PAD_LEFT),
                    'rfc' => $solicitud->rfc,
                    'sucursal_id' => $solicitud->sucursal_id,
                    'coordinador_id' => $solicitud->coordinador_id,
                    'verificador_id' => $solicitud->verificador_id,
                    'categoria_id' => $solicitud->categoria_id,
                    'aprobado_por' => $gerente->id,
                    'fecha_aprobacion' => now(),
                    'comentarios_verificador' => $solicitud->comentario_verificador,
                    'limite_credito' => $limiteCredito,
                    'puntos_acumulados' => 0,
                    'estado' => 'ACTIVO',
                ]);

                if ($solicitud->datos_familiares || $solicitud->datos_vehiculos || $solicitud->datos_vivienda || $solicitud->referencia_laboral) {
                    DistribuidorDatosExtras::query()->create([
                        'distribuidora_id' => $distribuidora->id,
                        'datos_familiares' => $solicitud->datos_familiares,
                        'datos_vehiculos' => $solicitud->datos_vehiculos,
                        'datos_vivienda' => $solicitud->datos_vivienda,
                        'referencia_laboral' => $solicitud->referencia_laboral,
                    ]);
                }

                // Asignar en el historial la vinculación entre Coordinador y Distribuidor
                if ($solicitud->coordinador_id) {
                    HistorialCoordinador::query()->create([
                        'distribuidor_id' => $distribuidoraUser->id,
                        'coordinador_id' => $solicitud->coordinador_id,
                        'fecha_inicio' => now(),
                        'asignado_por' => $gerente->id,
                        'motivo' => 'Asignación inicial tras aprobación de solicitud.',
                    ]);
                }

                $solicitud->estado = 'aprobado';
                $solicitud->decision_gerente = 'aprobado';
                $solicitud->limite_credito_asignado = (float) $data['limite_credito_asignado'];

                LogNuevoProveedor::query()->create([
                    'solicitud_id' => $solicitud->id,
                    'entidad_tipo' => 'SolicitudProveedor',
                    'entidad_id' => $solicitud->id,
                    'campo' => 'estado',
                    'valor_anterior' => 'verificado',
                    'valor_nuevo' => 'aprobado',
                    'modificado_por' => $gerente->id,
                    'fecha_hora' => now(),
                    'dispositivo' => $dispositivo,
                    'accion' => 'aprobacion_gerente',
                    'motivo' => 'Solicitud aprobada y usuario distribuidor generado.',
                ]);
            } else {
                $solicitud->estado = 'rechazado';
                $solicitud->decision_gerente = 'rechazado';

                LogNuevoProveedor::query()->create([
                    'solicitud_id' => $solicitud->id,
                    'entidad_tipo' => 'SolicitudProveedor',
                    'entidad_id' => $solicitud->id,
                    'campo' => 'estado',
                    'valor_anterior' => $solicitud->estado,
                    'valor_nuevo' => 'rechazado',
                    'modificado_por' => $gerente->id,
                    'fecha_hora' => now(),
                    'dispositivo' => $dispositivo,
                    'accion' => 'rechazo_gerente',
                    'motivo' => $data['comentario_gerente'] ?? 'Rechazado por decisión de gerencia.',
                ]);
            }

            $solicitud->gerente_id = $gerente->id;
            $solicitud->comentario_gerente = $data['comentario_gerente'] ?? null;
            $solicitud->fecha_decision = now();
            $solicitud->save();

            // El Coordinador solo se enteraba de que su solicitud pasó a verificación -- nunca
            // del resultado final de Gerencia, ni aprobado ni rechazado. Tenía que entrar a
            // revisar "Mis Solicitudes" a ciegas para saberlo.
            if ($solicitud->coordinador) {
                $this->notificacionService->crear(
                    $solicitud->coordinador,
                    $solicitud->estado === 'aprobado' ? 'solicitud_aprobada_gerente' : 'solicitud_rechazada_gerente',
                    'Solicitud '.$solicitud->nombre,
                    $gerente
                );
            }

            Log::debug('SolicitudProveedorService: Decisión de Gerencia procesada exitosamente', [
                'solicitud_id' => $solicitud->id,
                'estado' => $solicitud->estado,
            ]);

            return $solicitud->load(['datosPersonales.direccion', 'sucursal', 'coordinador', 'verificador', 'gerente', 'categoria', 'evidencias', 'logs']);
        });

        // Mail::send() es una llamada de red externa que puede tardar segundos -- hacerla DENTRO
        // de DB::transaction() arriesgaba mandar el correo de credenciales para una cuenta que
        // luego se revertía por cualquier falla posterior en la misma transacción (ej. una
        // reaprobación duplicada que revienta por el rfc único). Mandarlo aquí, ya fuera de la
        // transacción y solo si de verdad se creó la cuenta, evita eso.
        if ($distribuidoraUser) {
            Mail::to((string) $distribuidoraUser->email)->send(new PersonalCredencialesMail(
                (string) $distribuidoraUser->name,
                (string) $distribuidoraUser->email,
                (string) $passwordGenerada,
                'Distribuidora',
            ));
        }

        return $solicitudProcesada;
    }
}
