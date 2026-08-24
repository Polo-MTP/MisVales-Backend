<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\SolicitudAumentoCredito;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * La distribuidora (o su coordinador, en su nombre) pide un incremento a su línea de crédito.
 * El Gerente negocia: puede otorgar menos de lo pedido, pero nunca más -- si necesita más
 * crédito del que pidió, que mande una solicitud nueva. Al aprobar, el incremento se aplica de
 * inmediato al límite de la distribuidora; Distribuidora::aumentoCreditoSinConsumir() ya usa
 * fecha_decision para saber si el primer vale posterior a este aumento sigue bajo el tope de
 * cautela del 50%.
 */
final class SolicitudAumentoCreditoService
{
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    public function solicitar(Distribuidora $distribuidora, User $usuario, float $montoSolicitado, string $motivo): SolicitudAumentoCredito
    {
        $this->verificarAutoridadSobreDistribuidora($distribuidora, $usuario);

        if ($distribuidora->estado !== 'ACTIVO') {
            abort(422, 'Solo una distribuidora ACTIVA puede solicitar un aumento de crédito.');
        }

        if ($montoSolicitado <= 0) {
            abort(422, 'El monto solicitado debe ser mayor a cero.');
        }

        $yaTienePendiente = SolicitudAumentoCredito::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('estado', 'pendiente')
            ->exists();

        if ($yaTienePendiente) {
            abort(422, 'Esta distribuidora ya tiene una solicitud de aumento de crédito pendiente.');
        }

        /** @var SolicitudAumentoCredito $solicitud */
        $solicitud = SolicitudAumentoCredito::query()->create([
            'distribuidora_id' => $distribuidora->id,
            'solicitado_por' => $usuario->id,
            'limite_credito_anterior' => $distribuidora->limite_credito,
            'monto_solicitado' => $montoSolicitado,
            'motivo' => $motivo,
        ]);

        // Quien decide (Gerente de Sucursal) no tenía forma de saber que llegó una solicitud:
        // debía entrar a la pantalla de aumentos a ver "por si acaso".
        $this->notificacionService->notificarRolEnSucursal(
            'Gerente de Sucursal',
            $distribuidora->sucursal_id,
            'aumento_credito_solicitado',
            $distribuidora->nombre ?? $distribuidora->numero_distribuidora,
            $usuario
        );

        return $solicitud->fresh(['distribuidora.usuario.datosPersonales', 'solicitante']);
    }

    public function decidir(SolicitudAumentoCredito $solicitud, string $decision, ?float $montoOtorgado, ?string $comentario, User $gerente): SolicitudAumentoCredito
    {
        if ($solicitud->estado !== 'pendiente') {
            throw new DomainException('Esta solicitud ya fue resuelta.');
        }

        $role = $gerente->role?->name;
        $distribuidora = $solicitud->distribuidora;

        if ($role !== 'Gerente General' && (! $distribuidora || $distribuidora->sucursal_id !== $gerente->sucursal_id)) {
            abort(403, 'No puedes decidir solicitudes de otra sucursal.');
        }

        if ($decision !== 'aprobada') {
            $solicitud->update([
                'estado' => 'rechazada',
                'decidido_por' => $gerente->id,
                'comentario_decision' => $comentario,
                'fecha_decision' => now(),
            ]);

            $this->notificarDecision($solicitud, 'aumento_credito_rechazado', $gerente);

            return $solicitud->fresh(['distribuidora.usuario.datosPersonales', 'solicitante', 'decisor']);
        }

        if ($montoOtorgado === null || $montoOtorgado <= 0) {
            abort(422, 'Debes indicar el monto otorgado para aprobar la solicitud.');
        }

        if ($montoOtorgado > (float) $solicitud->monto_solicitado) {
            abort(422, 'El monto otorgado no puede ser mayor al monto solicitado.');
        }

        return DB::transaction(function () use ($solicitud, $montoOtorgado, $comentario, $gerente, $distribuidora): SolicitudAumentoCredito {
            // monto_otorgado es el LÍMITE TOTAL nuevo, no un incremento a sumar -- la
            // distribuidora pide "de $25,000 a $75,000" (ver limite_credito_anterior, guardado
            // como snapshot al solicitar, y la etiqueta "De X a Y" en el frontend). Sumarlo al
            // límite ya vigente duplicaba el otorgado de más en cada aprobación.
            $distribuidora->limite_credito = $montoOtorgado;
            $distribuidora->save();

            $solicitud->update([
                'estado' => 'aprobada',
                'monto_otorgado' => $montoOtorgado,
                'decidido_por' => $gerente->id,
                'comentario_decision' => $comentario,
                'fecha_decision' => now(),
            ]);

            $this->notificarDecision($solicitud, 'aumento_credito_aprobado', $gerente);

            return $solicitud->fresh(['distribuidora.usuario.datosPersonales', 'solicitante', 'decisor']);
        });
    }

    /**
     * Avisa a la distribuidora (y a quien pidió el aumento en su nombre, si fue el coordinador)
     * cómo quedó su solicitud -- antes tenía que volver a entrar a la pantalla a averiguarlo.
     */
    private function notificarDecision(SolicitudAumentoCredito $solicitud, string $accion, User $gerente): void
    {
        $recurso = $solicitud->estado === 'aprobada'
            ? 'Aumento otorgado: $'.number_format((float) $solicitud->monto_otorgado, 2)
            : 'Aumento solicitado: $'.number_format((float) $solicitud->monto_solicitado, 2);

        $avisados = [];

        if ($distribuidoraUsuario = $solicitud->distribuidora?->usuario) {
            $this->notificacionService->crear($distribuidoraUsuario, $accion, $recurso, $gerente);
            $avisados[] = $distribuidoraUsuario->id;
        }

        // El coordinador puede pedir el aumento en nombre de la distribuidora (ver solicitar):
        // si fue él, también necesita saber en qué quedó.
        if ($solicitante = $solicitud->solicitante) {
            if (! in_array($solicitante->id, $avisados, true)) {
                $this->notificacionService->crear($solicitante, $accion, $recurso, $gerente);
            }
        }
    }

    /**
     * Lista solicitudes visibles: Distribuidora ve las suyas; Coordinador las de su cartera;
     * Gerente de Sucursal las de su sucursal; Gerente General todas.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = SolicitudAumentoCredito::query()->with(['distribuidora.usuario.datosPersonales', 'solicitante', 'decisor']);

        $role = $usuario->role?->name;

        if ($role === 'Distribuidora') {
            $query->where('distribuidora_id', $usuario->distribuidora?->id);
        } elseif ($role === 'Coordinador') {
            $query->whereHas('distribuidora', fn ($q) => $q->where('coordinador_id', $usuario->id));
        } elseif ($role !== 'Gerente General') {
            $query->whereHas('distribuidora', fn ($q) => $q->where('sucursal_id', $usuario->sucursal_id));
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function verificarAutoridadSobreDistribuidora(Distribuidora $distribuidora, User $usuario): void
    {
        $role = $usuario->role?->name;

        if ($role === 'Distribuidora' && $usuario->distribuidora?->id === $distribuidora->id) {
            return;
        }

        if ($role === 'Coordinador' && $distribuidora->coordinador_id === $usuario->id) {
            return;
        }

        abort(403, 'No tienes autoridad para solicitar un aumento de crédito para esta distribuidora.');
    }
}
