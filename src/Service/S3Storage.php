<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use Aws\S3\S3Client;
use Aws\CommandPool;
use GuzzleHttp\Promise\PromiseInterface;

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

    /**
     * Upload multiple files asynchronously in parallel.
     * @param array<array{key:string,body:string,contentType:string}> $files
     * @param int $concurrency Maximum number of concurrent uploads (default 5)
     * @return void
     */
    public function putMultiple(array $files, int $concurrency = 5): void
    {
        if (empty($files)) {
            return;
        }

        $commands = [];
        foreach ($files as $file) {
            $commands[] = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => ltrim($file['key'], '/'),
                'Body' => $file['body'],
                'ContentType' => $file['contentType'],
                'CacheControl' => 'public, max-age=' . $this->cacheSeconds,
                'ACL' => 'public-read',
            ]);
        }

        $pool = new CommandPool($this->client, $commands, [
            'concurrency' => $concurrency,
            'rejected' => function ($reason, $index) {
                // Throw exception on first failure
                throw new \RuntimeException("S3 upload failed for file at index {$index}: " . $reason);
            },
        ]);

        $pool->promise()->wait();
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
