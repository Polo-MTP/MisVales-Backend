<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('el administrador no tiene acceso a los endpoints de negocio, solo a los de logs', function (): void {
    $role = Role::create(['name' => 'Administrador']);
    $admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/vales')->assertStatus(403);
    $this->getJson('/api/v1/distribuidoras')->assertStatus(403);
    $this->getJson('/api/v1/relaciones')->assertStatus(403);
    $this->getJson('/api/v1/conciliaciones')->assertStatus(403);

    $this->getJson('/api/v1/admin/logs')->assertStatus(200)
        ->assertJson(['success' => true]);

    $this->getJson('/api/v1/admin/historical-data')->assertStatus(200)
        ->assertJson(['success' => true]);
});
