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
     *
     * @return array{upload_url: string, path: string, public_url: string}
     */
    public function generatePresignedUploadUrl(
        string $originalFileName,
        string $contentType,
        string $folder = 'uploads',
        int $expiresMinutes = 15,
        string $acl = 'public-read'
    ): array {
        $cleanFolder = trim($folder, '/');
        $ext = pathinfo($originalFileName, PATHINFO_EXTENSION);
        $name = Str::slug(pathinfo($originalFileName, PATHINFO_FILENAME));
        $uniqueName = time() . '_' . Str::random(6) . '_' . ($name !== '' ? $name : 'file') . ($ext !== '' ? '.' . $ext : '');
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
     * Elimina un archivo del Space por su ruta relativa.
     */
    public function delete(string $path): bool
    {
        return Storage::disk('s3')->delete($path);
    }
}
