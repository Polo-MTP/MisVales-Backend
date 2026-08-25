<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Administrador se provisiona fuera de la app (seeder/tinker) -- ninguna regla de FormRequest
 * puede protegerlo, así que el candado real vive en la base de datos (ver la migración
 * add_administrador_unico_a_users_table y App\Models\User::booted()). Estos tests confirman
 * que ese candado de verdad detiene una segunda fila, sin importar por dónde se intente crear.
 */
it('permite crear el primer Administrador sin problema', function (): void {
    $rol = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);

    $admin = User::factory()->create(['role_id' => $rol->id, 'is_active' => true]);

    expect($admin->es_el_unico_administrador)->toBeTrue();
});

it('bloquea a nivel de base de datos crear un segundo Administrador', function (): void {
    $rol = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);
    User::factory()->create(['role_id' => $rol->id, 'is_active' => true]);

    expect(fn () => User::factory()->create(['role_id' => $rol->id, 'is_active' => true]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(User::where('role_id', $rol->id)->count())->toBe(1);
});

it('no cuenta usuarios de otros roles para el candado -- pueden existir muchos Cajera, Coordinador, etc.', function (): void {
    $rolAdmin = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);
    $rolCajera = Role::firstOrCreate(['name' => 'Cajera'], ['factor_count' => 2]);

    User::factory()->create(['role_id' => $rolAdmin->id, 'is_active' => true]);
    User::factory()->count(5)->create(['role_id' => $rolCajera->id, 'is_active' => true]);

    expect(User::where('role_id', $rolCajera->id)->count())->toBe(5);
});

it('libera el candado si el único Administrador cambia de rol, permitiendo dar de alta otro', function (): void {
    $rolAdmin = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);
    $rolCajera = Role::firstOrCreate(['name' => 'Cajera'], ['factor_count' => 2]);

    $admin = User::factory()->create(['role_id' => $rolAdmin->id, 'is_active' => true]);
    $admin->update(['role_id' => $rolCajera->id]);

    expect($admin->fresh()->es_el_unico_administrador)->toBeNull();

    $nuevoAdmin = User::factory()->create(['role_id' => $rolAdmin->id, 'is_active' => true]);
    expect($nuevoAdmin->es_el_unico_administrador)->toBeTrue();
});
