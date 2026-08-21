<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class SpacesStorageService
{
    private ?S3Client $client = null;

    /**
     * Obtiene la instancia del cliente S3 / DigitalOcean Spaces.
     */
    public function getClient(): S3Client
    {
        if ($this->client === null) {
            $key = config('filesystems.disks.s3.key', env('AWS_ACCESS_KEY_ID'));
            $secret = config('filesystems.disks.s3.secret', env('AWS_SECRET_ACCESS_KEY'));
            $bucket = config('filesystems.disks.s3.bucket', env('AWS_BUCKET'));

            if (empty($key) || empty($secret) || empty($bucket)) {
                throw new RuntimeException('Las credenciales de DigitalOcean Spaces (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET) no están configuradas.');
            }

            /** @var S3Client $s3Client */
            $s3Client = Storage::disk('s3')->getClient();
            $this->client = $s3Client;
        }

        return $this->client;
    }

    /**
     * Genera una URL prefirmada para subida directa (PUT) desde el frontend al Space.
     * ACL privada por defecto -- documentos como INE/comprobante de domicilio/contrato no
     * deben quedar accesibles para quien sea que consiga la URL, sin sesión de por medio.
     * Para mostrarlos después hay que pedir una URL de lectura firmada (ver
     * generatePresignedReadUrl()), no usar 'public_url' directo.
     *
     * @return array{upload_url: string, path: string, public_url: string}
     */
    public function generatePresignedUploadUrl(
        string $originalFileName,
        string $contentType,
        string $folder = 'uploads',
        int $expiresMinutes = 15,
        string $acl = 'private'
    ): array {
        $cleanFolder = trim($folder, '/');
        $ext = pathinfo($originalFileName, PATHINFO_EXTENSION);
        $name = Str::slug(pathinfo($originalFileName, PATHINFO_FILENAME));
        $uniqueName = time().'_'.Str::random(6).'_'.($name !== '' ? $name : 'file').($ext !== '' ? '.'.$ext : '');
        $path = "{$cleanFolder}/{$uniqueName}";

        $bucket = (string) config('filesystems.disks.s3.bucket', env('AWS_BUCKET'));
        $endpoint = rtrim((string) config('filesystems.disks.s3.endpoint', env('AWS_ENDPOINT')), '/');

        $client = $this->getClient();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key' => $path,
            'ACL' => $acl,
            'ContentType' => $contentType,
        ]);

        $requestUrl = $client->createPresignedRequest($command, "+{$expiresMinutes} minutes");
        $publicUrl = "{$endpoint}/{$bucket}/{$path}";

        return [
            'upload_url' => (string) $requestUrl->getUri(),
            'path' => $path,
            'public_url' => $publicUrl,
        ];
    }

    /**
     * Genera una URL prefirmada temporal (GET) para leer/ver un archivo privado del Space.
     * Acepta tanto la 'path' (Key de S3) como el 'public_url' que devolvió
     * generatePresignedUploadUrl() en su momento -- así los registros que ya guardaron la
     * URL completa (Evidencia.url_archivo, etc.) no necesitan migrarse.
     */
    public function generatePresignedReadUrl(string $pathOrUrl, int $expiresMinutes = 15): string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket', env('AWS_BUCKET'));
        $endpoint = rtrim((string) config('filesystems.disks.s3.endpoint', env('AWS_ENDPOINT')), '/');

        $path = $this->extraerPath($pathOrUrl, $endpoint, $bucket);

        $client = $this->getClient();
        $command = $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $path,
        ]);

        $requestUrl = $client->createPresignedRequest($command, "+{$expiresMinutes} minutes");

        return (string) $requestUrl->getUri();
    }

    /**
     * Elimina un archivo del Space por su ruta relativa.
     */
    public function delete(string $path): bool
    {
        return Storage::disk('s3')->delete($path);
    }

    /**
     * Reduce un 'public_url' completo ({endpoint}/{bucket}/{path}) a solo la Key de S3;
     * si ya viene como Key (no trae el endpoint), la regresa tal cual.
     */
    private function extraerPath(string $pathOrUrl, string $endpoint, string $bucket): string
    {
        $prefijo = "{$endpoint}/{$bucket}/";

        return str_starts_with($pathOrUrl, $prefijo)
            ? mb_substr($pathOrUrl, mb_strlen($prefijo))
            : ltrim($pathOrUrl, '/');
    }
}
