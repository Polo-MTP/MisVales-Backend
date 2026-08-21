<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($user);
});

it('rechaza un content_type que no está en la lista permitida', function (): void {
    $this->getJson('/api/v1/upload-url?file_name=archivo.html&content_type=text/html&folder=evidencias')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content_type']);
});

it('rechaza un content_type de svg (riesgo de XSS si se sube con ACL pública)', function (): void {
    $this->getJson('/api/v1/upload-url?file_name=logo.svg&content_type=image/svg%2Bxml&folder=evidencias')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content_type']);
});

it('acepta los content_type realmente usados por el aplicativo', function (): void {
    foreach (['image/jpeg', 'image/png', 'image/webp', 'application/pdf'] as $contentType) {
        $this->getJson('/api/v1/upload-url?file_name=archivo.jpg&content_type='.urlencode($contentType).'&folder=evidencias')
            ->assertJsonMissingValidationErrors(['content_type']);
    }
});

it('rechaza un folder con path traversal o slashes', function (): void {
    $this->getJson('/api/v1/upload-url?file_name=archivo.jpg&content_type=image/jpeg&folder='.urlencode('../../etc'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['folder']);

    $this->getJson('/api/v1/upload-url?file_name=archivo.jpg&content_type=image/jpeg&folder='.urlencode('evidencias/../otro'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['folder']);
});

it('acepta un folder con solo minúsculas, números y guiones', function (): void {
    $this->getJson('/api/v1/upload-url?file_name=archivo.jpg&content_type=image/jpeg&folder=evidencias-2026')
        ->assertJsonMissingValidationErrors(['folder']);
});
