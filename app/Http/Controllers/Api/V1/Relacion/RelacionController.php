<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Relacion;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Relacion\RelacionResource;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\User;
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Relacion\RelacionEstadoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RelacionController extends ApiController
{
    public function __construct(
        private readonly RelacionCalculoService $relacionCalculoService,
        private readonly RelacionEstadoService $relacionEstadoService,
    ) {}

    /**
     * Lista los cortes (relaciones), filtrables por distribuidora, estado y referencia de pago.
     * La Distribuidora solo ve los suyos (para saber cuánto le toca pagar cada quincena); el
     * staff los ve según su alcance.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $query = Relacion::query()->with(['distribuidora.usuario.datosPersonales', 'sucursal', 'categoriaSnapshot']);

        // La cajera necesita poder buscar la relación de un pago sin adivinar el ID a mano
        // (ver flujo de conciliación manual), pero solo dentro de su propia sucursal. El
        // Gerente de Sucursal se limita igual (mismo patrón que DistribuidoraService::listar()).
        if (in_array($usuario->role?->name, ['Cajera', 'Gerente de Sucursal'], true)) {
            $query->where('sucursal_id', $usuario->sucursal_id ?? 0);
        }

        // El Coordinador solo ve las relaciones de las distribuidoras que él coordina, no
        // de todas las sucursales (mismo patrón que DistribuidoraService::listar()).
        if ($usuario->role?->name === 'Coordinador') {
            $query->whereHas('distribuidora', fn ($q) => $q->where('coordinador_id', $usuario->id));
        }

        if ($usuario->role?->name === 'Distribuidora') {
            $query->where('distribuidora_id', $usuario->distribuidora?->id ?? 0);
        }

        if ($request->filled('distribuidora_id')) {
            $query->where('distribuidora_id', $request->integer('distribuidora_id'));
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('referencia_pago')) {
            $query->where('referencia_pago', 'like', '%'.$request->string('referencia_pago').'%');
        }

        $relaciones = $query->latest('fecha_corte')->paginate((int) $request->input('per_page', 15));

        return $this->success(
            data: RelacionResource::collection($relaciones)->response()->getData(true),
            message: 'Lista de relaciones obtenida exitosamente.'
        );
    }

    /**
     * Muestra el detalle de un corte con sus cuotas por vale.
     */
    public function show(Relacion $relacion, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        if (in_array($usuario->role?->name, ['Cajera', 'Gerente de Sucursal'], true) && $relacion->sucursal_id !== $usuario->sucursal_id) {
            abort(403, 'No puedes ver relaciones de otra sucursal.');
        }

        if ($usuario->role?->name === 'Coordinador' && $relacion->distribuidora?->coordinador_id !== $usuario->id) {
            abort(403, 'No puedes ver relaciones de distribuidoras que no coordinas.');
        }

        if ($usuario->role?->name === 'Distribuidora' && $relacion->distribuidora_id !== $usuario->distribuidora?->id) {
            abort(403, 'No puedes ver relaciones de otra distribuidora.');
        }

        $relacion->load(['distribuidora.usuario.datosPersonales', 'sucursal', 'categoriaSnapshot', 'detalles.vale', 'detalles.cliente.datosPersonales', 'detalles.producto']);

        return $this->success(
            data: new RelacionResource($relacion),
            message: 'Detalle de relación obtenido exitosamente.'
        );
    }

    /**
     * Cuándo será el próximo corte de la distribuidora y cuánto se estima que le va a tocar
     * pagar, ANTES de que ese corte exista — sin esto no había forma de saberlo hasta que el
     * corte ya se había generado. Si quien pregunta es una Distribuidora, es la suya; el
     * staff puede pasar ?distribuidora_id= para consultar la de alguien más.
     */
    public function proximoPago(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $distribuidora = $usuario->role?->name === 'Distribuidora'
            ? $usuario->distribuidora
            : ($request->filled('distribuidora_id') ? Distribuidora::query()->find($request->integer('distribuidora_id')) : null);

        if (! $distribuidora) {
            abort(404, 'No se encontró la distribuidora a consultar.');
        }

        $resultado = $this->relacionCalculoService->proximoPago($distribuidora);

        return $this->success(
            data: [
                ...$resultado,
                'nota' => 'Estimado si paga puntual, con las reglas vigentes hoy. No incluye recargo por atraso.',
            ],
            message: 'Próximo pago estimado obtenido exitosamente.'
        );
    }

    /**
     * Genera el corte de una distribuidora específica, o el corte del día para todas
     * las distribuidoras que correspondan, si no se indica distribuidora_id.
     */
    public function generar(Request $request): JsonResponse
    {
        $request->validate([
            'distribuidora_id' => ['nullable', 'integer', 'exists:distribuidoras,id'],
            'fecha_corte' => ['nullable', 'date'],
        ]);

        try {
            if ($request->filled('distribuidora_id')) {
                $distribuidora = Distribuidora::query()->findOrFail($request->integer('distribuidora_id'));
                $relacion = $this->relacionCalculoService->generarParaDistribuidora($distribuidora, $request->input('fecha_corte'));

                if (! $relacion) {
                    return $this->success(message: 'La distribuidora no tiene vales con saldo pendiente; no se generó relación.');
                }

                return $this->created(
                    data: new RelacionResource($relacion->load(['detalles.vale', 'detalles.cliente', 'detalles.producto', 'distribuidora.usuario.datosPersonales', 'sucursal', 'categoriaSnapshot'])),
                    message: 'Relación generada exitosamente.'
                );
            }

            $resultado = $this->relacionCalculoService->generarCortesDelDia($request->input('fecha_corte'));
            $generadas = $resultado['generadas'];
            $errores = $resultado['errores'];

            $mensaje = count($generadas).' relación(es) generada(s) para el corte del día.';
            if ($errores !== []) {
                $mensaje .= ' '.count($errores).' distribuidora(s) fallaron y se omitieron (ver "errores").';
            }

            return $this->created(
                data: [
                    'relaciones' => RelacionResource::collection(array_values($generadas)),
                    'errores' => $errores,
                ],
                message: $mensaje
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Otorga un perdón de recargo/interés sobre el corte, o lo marca como pérdida si se
     * alcanzó el límite de perdones permitidos.
     */
    public function perdonar(Request $request, Relacion $relacion): JsonResponse
    {
        $request->validate([
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $relacion = $this->relacionEstadoService->perdonar($relacion, $usuario, $request->input('motivo'));

            return $this->success(
                data: new RelacionResource($relacion),
                message: $relacion->estado === 'perdonada' ? 'Relación perdonada exitosamente.' : 'Límite de perdones alcanzado: la relación se marcó como pérdida.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
