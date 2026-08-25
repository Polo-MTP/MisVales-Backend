<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Usuario\CrearGerenteGeneralRequest;
use App\Http\Requests\Api\V1\Usuario\CrearGerenteSucursalRequest;
use App\Http\Requests\Api\V1\Usuario\CrearPersonalSucursalRequest;
use App\Http\Requests\Api\V1\Usuario\ReasignarPersonalRequest;
use App\Http\Resources\UserResource;
use App\Mail\PersonalCredencialesMail;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use App\Services\Usuario\MovimientosAutorizadosService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UsuarioController extends ApiController
{
    public function __construct(
        private readonly MovimientosAutorizadosService $movimientosAutorizadosService,
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Lista usuarios activos, opcionalmente filtrados por rol (?filter[role]=Verificador).
     * Igual que en alta-proveedor: Gerente General y Administrador ven todas las sucursales,
     * el resto de roles solo ve usuarios de su propia sucursal.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $query = User::query()->where('is_active', true);

        if ($authUser->role?->name !== 'Gerente General' && $authUser->role?->name !== 'Administrador') {
            $query->where('sucursal_id', $authUser->sucursal_id);
        }

        $roleFiltro = $request->input('filter.role');
        if ($roleFiltro) {
            $query->whereHas('role', fn ($q) => $q->where('name', $roleFiltro));
        }

        $usuarios = $query->with(['role', 'sucursal'])->orderBy('name')->get();

        return $this->success(UserResource::collection($usuarios));
    }

    /**
     * Feed cronológico de todo lo que el usuario autenticado ha autorizado/decidido, sin
     * importar la tabla de origen (perdones, conciliaciones, aumentos de crédito, altas de
     * proveedor, cambios de estado de distribuidora, cortes generados a mano, etc.). Solo ve
     * lo suyo — no hay forma de consultar los movimientos de alguien más desde aquí.
     */
    public function misAutorizaciones(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $movimientos = $this->movimientosAutorizadosService->listar(
            $usuario,
            $request->input('desde'),
            $request->input('hasta'),
            (int) $request->input('per_page', 15),
            (int) $request->input('page', 1),
        );

        return $this->success($movimientos);
    }

    /**
     * Da de alta un Gerente de Sucursal — el rol queda fijo aquí, no lo elige quien manda la
     * petición, precisamente para que este endpoint no sirva para crear ningún otro rol
     * (Administrador incluido). La cuenta nace activa y con el correo ya verificado, igual
     * que el resto de altas hechas por staff (ver SolicitudProveedorService).
     *
     * La contraseña la genera el sistema y se manda por correo, igual que en
     * crearPersonalSucursal() -- quien da de alta nunca conoce la contraseña de otra persona.
     */
    public function crearGerenteSucursal(CrearGerenteSucursalRequest $request): JsonResponse
    {
        $rolGerenteSucursal = Role::query()->where('name', 'Gerente de Sucursal')->firstOrFail();

        $sucursal = Sucursal::query()->find($request->integer('sucursal_id'));
        if (! $sucursal || ! $sucursal->is_active) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'La sucursal indicada está deshabilitada y no puede recibir personal nuevo.',
            ]);
        }

        $data = $request->validated();
        $passwordGenerada = Str::password(22);

        $usuario = DB::transaction(function () use ($data, $rolGerenteSucursal, $sucursal, $request, $passwordGenerada): User {
            $datosId = $this->crearDatosPersonalesYDireccion($data);

            $usuario = User::query()->create([
                'name' => $this->nombreCompleto($data),
                'email' => $request->string('email'),
                'password' => Hash::make($passwordGenerada),
                'role_id' => $rolGerenteSucursal->id,
                'sucursal_id' => $sucursal->id,
                'datos_id' => $datosId,
                'rfc' => $data['rfc'],
                'referencia_laboral' => $data['referencia_laboral'] ?? null,
                'is_active' => true,
            ]);

            // 'email_verified_at' no está en $fillable de User (a propósito, no se llena desde
            // requests normales) -- se asigna aparte y se guarda, en vez de mandarlo en el
            // create() de arriba, donde quedaría silenciosamente ignorado.
            $usuario->email_verified_at = now();
            $usuario->save();

            return $usuario;
        });

        Mail::to((string) $usuario->email)->send(new PersonalCredencialesMail(
            (string) $usuario->name,
            (string) $usuario->email,
            (string) $passwordGenerada,
            (string) $rolGerenteSucursal->name,
        ));

        return $this->created(new UserResource($usuario->load(['role', 'sucursal'])));
    }

    /**
     * Da de alta un Gerente General. Solo Administrador puede llegar aquí -- no hay ningún
     * flujo para dar de alta cuentas de Administrador (se provisiona fuera de la app), y
     * Gerente General no puede crear otro Gerente General (evita que la cadena de mando se
     * auto-perpetúe sin que Administrador se entere). El rol queda fijo en el controller. Un
     * Gerente General no está atado a ninguna sucursal en particular (ve todo), así que se
     * asigna a la matriz en vez de pedir sucursal_id en el request.
     */
    public function crearGerenteGeneral(CrearGerenteGeneralRequest $request): JsonResponse
    {
        $rolGerenteGeneral = Role::query()->where('name', 'Gerente General')->firstOrFail();
        $matriz = Sucursal::query()->where('es_matriz', true)->first();

        if (! $matriz) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'No existe ninguna sucursal matriz. Crea una en Sucursales antes de dar de alta un Gerente General.',
            ]);
        }

        $data = $request->validated();
        $passwordGenerada = Str::password(22);

        $usuario = DB::transaction(function () use ($data, $rolGerenteGeneral, $matriz, $request, $passwordGenerada): User {
            $datosId = $this->crearDatosPersonalesYDireccion($data);

            $usuario = User::query()->create([
                'name' => $this->nombreCompleto($data),
                'email' => $request->string('email'),
                'password' => Hash::make($passwordGenerada),
                'role_id' => $rolGerenteGeneral->id,
                'sucursal_id' => $matriz?->id,
                'datos_id' => $datosId,
                'rfc' => $data['rfc'],
                'referencia_laboral' => $data['referencia_laboral'] ?? null,
                'is_active' => true,
            ]);

            $usuario->email_verified_at = now();
            $usuario->save();

            return $usuario;
        });

        Mail::to((string) $usuario->email)->send(new PersonalCredencialesMail(
            (string) $usuario->name,
            (string) $usuario->email,
            (string) $passwordGenerada,
            (string) $rolGerenteGeneral->name,
        ));

        return $this->created(new UserResource($usuario->load(['role', 'sucursal'])));
    }

    /**
     * Da de alta Coordinador, Verificador o Cajera. El rol viene del request (ya restringido
     * a esas 3 opciones por CrearPersonalSucursalRequest), pero sucursal_id/gerente_id NUNCA
     * se toman tal cual del request cuando quien pide es Gerente de Sucursal: se sobreescriben
     * con su propia sucursal y su propio id, para que no pueda darse de alta personal fuera de
     * su sucursal ni asignárselo a otro gerente. Cuando es Gerente General sí manda ambos
     * explícitamente, y se valida que el gerente indicado sea realmente Gerente de Sucursal de
     * esa misma sucursal.
     *
     * La contraseña la genera el sistema (no la elige quien da de alta) y se manda por correo
     * al nuevo usuario -- así nadie más que él llega a conocerla. Si quien asigna es distinto
     * al gerente (Gerente General dando de alta bajo un Gerente de Sucursal específico), ese
     * gerente recibe una notificación de que tiene personal nuevo a su cargo.
     */
    public function crearPersonalSucursal(CrearPersonalSucursalRequest $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $rol = Role::query()->where('name', $request->string('rol'))->firstOrFail();

        if ($authUser->role?->name === 'Gerente de Sucursal') {
            $sucursalId = $authUser->sucursal_id;
            $gerente = $authUser;
        } else {
            $sucursalId = $request->integer('sucursal_id');
            $gerente = User::query()->with('role')->find($request->integer('gerente_id'));

            if (! $gerente || $gerente->role?->name !== 'Gerente de Sucursal' || $gerente->sucursal_id !== $sucursalId) {
                throw ValidationException::withMessages([
                    'gerente_id' => 'El gerente indicado debe ser Gerente de Sucursal de la sucursal seleccionada.',
                ]);
            }

            if (! $gerente->is_active) {
                throw ValidationException::withMessages([
                    'gerente_id' => 'El gerente indicado está deshabilitado y no puede recibir personal a su cargo.',
                ]);
            }
        }

        // La sucursal debe seguir activa -- cubre tanto el caso en que Gerente General elige una
        // sucursal deshabilitada, como el caso (más raro) de que la propia sucursal del Gerente de
        // Sucursal se haya deshabilitado después de que él inició sesión.
        $sucursal = Sucursal::query()->find($sucursalId);
        if (! $sucursal || ! $sucursal->is_active) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'La sucursal indicada está deshabilitada y no puede recibir personal nuevo.',
            ]);
        }

        $data = $request->validated();
        $passwordGenerada = Str::password(22);

        $usuario = DB::transaction(function () use ($data, $rol, $sucursalId, $gerente, $request, $passwordGenerada): User {
            $datosId = $this->crearDatosPersonalesYDireccion($data);

            $usuario = User::query()->create([
                'name' => $this->nombreCompleto($data),
                'email' => $request->string('email'),
                'password' => Hash::make($passwordGenerada),
                'role_id' => $rol->id,
                'sucursal_id' => $sucursalId,
                'gerente_id' => $gerente->id,
                'datos_id' => $datosId,
                'rfc' => $data['rfc'],
                'referencia_laboral' => $data['referencia_laboral'] ?? null,
                'is_active' => true,
            ]);

            $usuario->email_verified_at = now();
            $usuario->save();

            return $usuario;
        });

        // $usuario->name/->email ya son strings reales (vienen de $data, no de un Stringable de
        // $request->string()) -- Mail::to() necesita un string real, si no intenta leer ->name
        // de ese objeto como si fuera un destinatario con nombre y truena con un BadMethodCallException.
        Mail::to((string) $usuario->email)->send(new PersonalCredencialesMail(
            (string) $usuario->name,
            (string) $usuario->email,
            (string) $passwordGenerada,
            (string) $rol->name,
        ));

        if ($gerente->id !== $authUser->id) {
            $this->notificacionService->crear($gerente, 'personal_asignado', $usuario->name.' ('.$rol->name.')', $authUser);
        }

        return $this->created(new UserResource($usuario->load(['role', 'sucursal', 'gerente'])));
    }

    /**
     * Mueve TODO el personal (Coordinador/Verificador/Cajera) de un Gerente de Sucursal a otro
     * -- típicamente cuando el de origen deja la empresa/sucursal. Solo Gerente General: a
     * diferencia de reasignarCoordinador (que un Gerente de Sucursal puede hacer sobre su propia
     * cartera), aquí un Gerente de Sucursal se estaría quedando sin equipo, así que es decisión
     * de Gerente General. Ambos gerentes deben ser de la MISMA sucursal -- el personal no cambia
     * de sucursal_id en esta operación, solo de a quién reporta.
     */
    public function reasignarPersonal(ReasignarPersonalRequest $request): JsonResponse
    {
        $gerenteOrigen = User::query()->with('role')->findOrFail($request->integer('gerente_origen_id'));
        $gerenteDestino = User::query()->with('role')->findOrFail($request->integer('gerente_destino_id'));

        if ($gerenteOrigen->role?->name !== 'Gerente de Sucursal' || $gerenteDestino->role?->name !== 'Gerente de Sucursal') {
            throw new DomainException('Ambos usuarios deben tener el rol Gerente de Sucursal.');
        }

        if ($gerenteOrigen->id === $gerenteDestino->id) {
            throw new DomainException('El gerente destino debe ser diferente del gerente de origen.');
        }

        if ($gerenteDestino->sucursal_id !== $gerenteOrigen->sucursal_id) {
            throw new DomainException('El gerente destino debe pertenecer a la misma sucursal que el de origen.');
        }

        if (! $gerenteDestino->is_active) {
            throw new DomainException('El gerente destino está deshabilitado y no puede recibir personal a su cargo.');
        }

        /** @var User $usuario */
        $usuario = $request->user();

        $total = DB::transaction(function () use ($gerenteOrigen, $gerenteDestino): int {
            $personal = User::query()->where('gerente_id', $gerenteOrigen->id)->lockForUpdate()->get();

            foreach ($personal as $miembro) {
                $miembro->gerente_id = $gerenteDestino->id;
                $miembro->save();
            }

            return $personal->count();
        });

        if ($total > 0) {
            $this->notificacionService->crear(
                $gerenteDestino,
                'personal_asignado',
                $total.' persona(s) reasignada(s) de '.$gerenteOrigen->name,
                $usuario
            );
        }

        return $this->success(
            data: ['personal_reasignado' => $total],
            message: "{$total} persona(s) reasignada(s) de {$gerenteOrigen->name} a {$gerenteDestino->name}."
        );
    }

    /**
     * Crea Dirección + DatosPersonales para una alta de personal interno y regresa el id de
     * DatosPersonales listo para 'users.datos_id' -- mismo patrón que
     * SolicitudProveedorService::crearSolicitud() usa para una distribuidora, para que el
     * expediente de cualquier cuenta (interna o externa) viva en el mismo lugar.
     *
     * @param  array<string, mixed>  $data  Validado por ValidaDatosPersonales.
     */
    private function crearDatosPersonalesYDireccion(array $data): int
    {
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

        return $datosPersonales->id;
    }

    /**
     * 'users.name' no se captura directo -- se calcula de nombre/apellidos, mismo criterio que
     * Distribuidora::getNombreAttribute(), para no duplicar la misma información en dos lugares
     * que podrían desincronizarse.
     *
     * @param  array<string, mixed>  $data
     */
    private function nombreCompleto(array $data): string
    {
        return trim($data['nombre'].' '.$data['apellido_paterno'].' '.($data['apellido_materno'] ?? ''));
    }
}
