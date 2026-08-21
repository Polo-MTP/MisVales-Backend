<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Services\Storage\SpacesStorageService;
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

function configurarSpacesFake(): void
{
    config()->set('filesystems.disks.s3.key', 'test-key');
    config()->set('filesystems.disks.s3.secret', 'test-secret');
    config()->set('filesystems.disks.s3.bucket', 'test-bucket');
    config()->set('filesystems.disks.s3.region', 'us-east-1');
    config()->set('filesystems.disks.s3.endpoint', 'https://fake.digitaloceanspaces.com');
    config()->set('filesystems.disks.s3.use_path_style_endpoint', true);
}

it('los archivos se suben con ACL privada por defecto, no pública', function (): void {
    $parametro = (new ReflectionMethod(SpacesStorageService::class, 'generatePresignedUploadUrl'))
        ->getParameters()[4];

    expect($parametro->getName())->toBe('acl')
        ->and($parametro->getDefaultValue())->toBe('private');
});

it('exige el parámetro path para generar una URL de lectura', function (): void {
    $this->getJson('/api/v1/read-url')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['path']);
});

it('genera una URL de lectura firmada para un path directo (Key de S3)', function (): void {
    configurarSpacesFake();

    $response = $this->getJson('/api/v1/read-url?path='.urlencode('evidencias/123_abcdef_foto.jpg'));

    $response->assertStatus(200);
    $readUrl = $response->json('data.read_url');
    expect($readUrl)->toContain('evidencias/123_abcdef_foto.jpg')
        ->and($readUrl)->toContain('X-Amz-Signature');
});

it('genera una URL de lectura firmada aunque le manden el public_url completo que se guardó al subir', function (): void {
    configurarSpacesFake();

    $publicUrl = 'https://fake.digitaloceanspaces.com/test-bucket/evidencias/123_abcdef_foto.jpg';

    $response = $this->getJson('/api/v1/read-url?path='.urlencode($publicUrl));

    $response->assertStatus(200);
    $readUrl = $response->json('data.read_url');
    // No debe quedar el endpoint/bucket duplicado dentro de la Key firmada.
    expect($readUrl)->toContain('/test-bucket/evidencias/123_abcdef_foto.jpg')
        ->and(mb_substr_count($readUrl, 'test-bucket'))->toBe(1);
});
