<?php

declare(strict_types=1);

use App\Mail\PersonalCredencialesMail;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    Mail::fake();
});

function crearGerenteGeneralUsr(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente General puede dar de alta un Gerente de Sucursal, con contraseña generada y enviada por correo', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Nuevo Gerente',
        'email' => 'nuevo.gerente@example.com',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo Gerente')
        ->assertJsonPath('data.role.name', 'Gerente de Sucursal')
        ->assertJsonPath('data.sucursal_id', $sucursal->id)
        ->assertJsonMissingPath('data.password');

    $creado = User::where('email', 'nuevo.gerente@example.com')->first();
    expect($creado)->not->toBeNull()
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->email_verified_at)->not->toBeNull()
        ->and($creado->role->name)->toBe('Gerente de Sucursal');

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.gerente@example.com') && strlen($mail->password) >= 22);
});

it('no se puede colar otro rol -- el endpoint siempre crea Gerente de Sucursal, ignora cualquier role_id que manden', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Intento Admin',
        'email' => 'intento@example.com',
        'sucursal_id' => $sucursal->id,
        'role_id' => 999,
        'role' => 'Administrador',
    ]);

    $response->assertStatus(201);
    expect(User::where('email', 'intento@example.com')->first()->role->name)->toBe('Gerente de Sucursal');
});

it('ningún otro rol puede dar de alta un Gerente de Sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Nuevo Gerente',
        'email' => 'otro@example.com',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(403);
});

it('rechaza dar de alta un Gerente de Sucursal en una sucursal deshabilitada', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Cerrada', 'codigo' => 'SUC-CERRADA', 'es_matriz' => false, 'is_active' => false]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Gerente Sucursal Cerrada',
        'email' => 'cerrada@example.com',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    expect(User::where('email', 'cerrada@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});
