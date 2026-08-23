<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Usuario\CrearGerenteSucursalRequest;
use App\Http\Requests\Api\V1\Usuario\CrearPersonalSucursalRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Usuario\MovimientosAutorizadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UsuarioController extends ApiController
{
    public function __construct(
        private readonly MovimientosAutorizadosService $movimientosAutorizadosService,
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
     */
    public function crearGerenteSucursal(CrearGerenteSucursalRequest $request): JsonResponse
    {
        $rolGerenteSucursal = Role::query()->where('name', 'Gerente de Sucursal')->firstOrFail();

        $usuario = User::query()->create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'role_id' => $rolGerenteSucursal->id,
            'sucursal_id' => $request->integer('sucursal_id'),
            'is_active' => true,
        ]);

        // 'email_verified_at' no está en $fillable de User (a propósito, no se llena desde
        // requests normales) -- se asigna aparte y se guarda, en vez de mandarlo en el
        // create() de arriba, donde quedaría silenciosamente ignorado.
        $usuario->email_verified_at = now();
        $usuario->save();

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
     */
    public function crearPersonalSucursal(CrearPersonalSucursalRequest $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $rol = Role::query()->where('name', $request->string('rol'))->firstOrFail();

        if ($authUser->role?->name === 'Gerente de Sucursal') {
            $sucursalId = $authUser->sucursal_id;
            $gerenteId = $authUser->id;
        } else {
            $sucursalId = $request->integer('sucursal_id');
            $gerenteId = $request->integer('gerente_id');

            $gerente = User::query()->with('role')->find($gerenteId);
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

        $usuario = User::query()->create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'role_id' => $rol->id,
            'sucursal_id' => $sucursalId,
            'gerente_id' => $gerenteId,
            'is_active' => true,
        ]);

        $usuario->email_verified_at = now();
        $usuario->save();

        return $this->created(new UserResource($usuario->load(['role', 'sucursal', 'gerente'])));
    }
}
