<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('un intento de login fallido no genera ruido en audit_log (User no está en el observer genérico)', function (): void {
    $role = Role::query()->where('name', 'Cajera')->firstOrFail();
    $user = User::factory()->create(['password' => bcrypt('Passw0rd1'), 'role_id' => $role->id]);

    // El propio User::factory()->create() ya deja UNA fila (User.creado, vía el
    // listener dedicado de creación) — ese es el rastro que sí queremos conservar.
    $auditCountTrasCrear = AuditLog::where('resource', 'User#'.$user->id)->count();
    expect($auditCountTrasCrear)->toBe(1);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'incorrecta', 'recaptcha' => 'bypass-recaptcha'])->assertStatus(401);
    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'incorrecta', 'recaptcha' => 'bypass-recaptcha'])->assertStatus(401);

    // failed_attempts sube y el user se guarda en cada intento — si User estuviera en el
    // observer genérico (created/updated/deleted), esto habría agregado dos filas más
    // "User.actualizado" en audit_log. El conteo debe quedarse igual que antes de loguear.
    expect($user->fresh()->failed_attempts)->toBe(2)
        ->and(AuditLog::where('resource', 'User#'.$user->id)->count())->toBe($auditCountTrasCrear);
});
