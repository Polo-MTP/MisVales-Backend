<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Fuerza a que cualquier consulta de este modelo (como validar si el token existe)
     * se haga siempre a la base de datos de Escritura (Maestro), evitando condiciones de carrera
     * por retardo de replicación en esquemas Read/Write split.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQuery()
    {
        return parent::newQuery()->useWritePdo();
    }
}
