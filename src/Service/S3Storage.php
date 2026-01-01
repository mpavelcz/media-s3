<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use Aws\S3\S3Client;

final class S3Storage
{
    private S3Client $client;
    private string $bucket;
    private string $publicBaseUrl;
    private int $cacheSeconds;

    /** @param array{endpoint:string,region:string,bucket:string,accessKey:string,secretKey:string,publicBaseUrl?:string,cacheSeconds?:int} $cfg */
    public function __construct(array $cfg)
    {
        $this->bucket = $cfg['bucket'];
        $this->publicBaseUrl = $cfg['publicBaseUrl'] ?? '';
        $this->cacheSeconds = (int) ($cfg['cacheSeconds'] ?? 31536000);

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $cfg['region'],
            'endpoint' => $cfg['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $cfg['accessKey'],
                'secret' => $cfg['secretKey'],
            ],
        ]);
    }

    public function put(string $key, string $body, string $contentType): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($key, '/'),
            'Body' => $body,
            'ContentType' => $contentType,
            'CacheControl' => 'public, max-age=' . $this->cacheSeconds,
            'ACL' => 'public-read',
        ]);
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => ltrim($key, '/'),
        ]);
    }

    public function publicUrl(string $key): string
    {
        if ($this->publicBaseUrl === '') {
            return ltrim($key, '/'); // fallback (ideálně nastav publicBaseUrl)
        }
        return rtrim($this->publicBaseUrl, '/') . '/' . ltrim($key, '/');
    }
}
