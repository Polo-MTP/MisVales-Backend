<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('el Gerente General sube el contrato de una distribuidora y queda expuesto en el resource', function (): void {
    Storage::fake('public');

    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);
    $roleGerente = Role::firstOrCreate(['name' => 'Gerente General']);

    $usuarioDistribuidora = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $gerente = User::factory()->create(['role_id' => $roleGerente->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuarioDistribuidora->id, 'numero_distribuidora' => 'DIST-TEST', 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    Sanctum::actingAs($gerente);

    $response = $this->postJson("/api/v1/distribuidoras/{$distribuidora->id}/contrato", [
        'archivo' => UploadedFile::fake()->create('contrato.pdf', 200, 'application/pdf'),
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.contrato_url', fn ($url) => str_contains((string) $url, 'contratos/'));

    expect($distribuidora->fresh()->contrato_url)->not->toBeNull();
});
