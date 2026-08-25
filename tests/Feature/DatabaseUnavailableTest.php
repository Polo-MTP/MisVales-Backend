<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

/**
 * Connection::runQueryCallback() envuelve CUALQUIER falla del driver PDO en QueryException --
 * incluida una caída total de la BD (conexión rechazada, timeout, "server has gone away").
 * bootstrap/app.php distingue esos casos (SERVICE_UNAVAILABLE, 503, reintentable) de una
 * QueryException por un bug real de query (columna inexistente, sintaxis inválida...), que debe
 * seguir cayendo en el catch-all genérico (SERVER_ERROR, 500) porque reintentar no lo arregla.
 */
it('una QueryException por conexión perdida (BD caída/timeout) trae error_code SERVICE_UNAVAILABLE', function (): void {
    Route::middleware(['api'])->get('/__test/bd-caida', function (): never {
        throw new QueryException(
            'mysql',
            'select * from vales',
            [],
            new PDOException('SQLSTATE[HY000] [2002] Connection refused')
        );
    });

    $this->getJson('/__test/bd-caida')
        ->assertStatus(503)
        ->assertJsonPath('error_code', ApiErrorCode::SERVICE_UNAVAILABLE->value)
        ->assertJsonPath('success', false);
});

it('una QueryException por un bug real de query (no de conectividad) sigue cayendo en SERVER_ERROR', function (): void {
    Route::middleware(['api'])->get('/__test/query-rota', function (): never {
        throw new QueryException(
            'mysql',
            'select columna_que_no_existe from vales',
            [],
            new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'columna_que_no_existe' in 'field list'")
        );
    });

    $this->getJson('/__test/query-rota')
        ->assertStatus(500)
        ->assertJsonPath('error_code', ApiErrorCode::SERVER_ERROR->value)
        ->assertJsonPath('success', false);
});
