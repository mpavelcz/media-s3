<?php declare(strict_types=1);

namespace MediaS3\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

final class RabbitPublisher
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $vhost;
    private string $queue;
    private ?string $dlq;

    private ?AMQPStreamConnection $conn = null;
    private ?AMQPChannel $channel = null;

    /** @param array{host?:string,port?:int,user?:string,pass?:string,vhost?:string,queue?:string,dlq?:string|null} $cfg */
    public function __construct(array $cfg = [])
    {
        $this->host = (string) ($cfg['host'] ?? 'rabbit');
        $this->port = (int) ($cfg['port'] ?? 5672);
        $this->user = (string) ($cfg['user'] ?? 'guest');
        $this->pass = (string) ($cfg['pass'] ?? 'guest');
        $this->vhost = (string) ($cfg['vhost'] ?? '/');
        $this->queue = (string) ($cfg['queue'] ?? 'media.process');
        $this->dlq = $cfg['dlq'] ?? null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function ensureConnected(): void
    {
        if ($this->conn !== null && $this->conn->isConnected()) {
            return;
        }

        $this->conn = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass, $this->vhost);
        $this->channel = $this->conn->channel();
        $this->channel->queue_declare($this->queue, false, true, false, false);

        // Declare DLQ if configured
        if ($this->dlq !== null) {
            $this->channel->queue_declare($this->dlq, false, true, false, false);
        }
    }

    private function disconnect(): void
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (\Throwable $e) {
                // Ignore errors on close
            }
            $this->channel = null;
        }

        if ($this->conn !== null) {
            try {
                $this->conn->close();
            } catch (\Throwable $e) {
                // Ignore errors on close
            }
            $this->conn = null;
        }
    }

    public function publishProcessAsset(int $assetId): void
    {
        try {
            $this->doPublish($assetId);
        } catch (\Throwable $e) {
            // On error, disconnect and retry once
            $this->disconnect();
            $this->doPublish($assetId);
        }
    }

    /**
     * Publish asset processing message with temp file path (for local uploads)
     */
    public function publishProcessAssetWithFile(int $assetId, string $tempFilePath): void
    {
        try {
            $this->doPublishWithFile($assetId, $tempFilePath);
        } catch (\Throwable $e) {
            // On error, disconnect and retry once
            $this->disconnect();
            $this->doPublishWithFile($assetId, $tempFilePath);
        }
    }

    private function doPublish(int $assetId): void
    {
        $this->ensureConnected();

        $payload = json_encode(['assetId' => $assetId], JSON_THROW_ON_ERROR);
        $msg = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->channel->basic_publish($msg, '', $this->queue);
    }

    private function doPublishWithFile(int $assetId, string $tempFilePath): void
    {
        $this->ensureConnected();

        $payload = json_encode([
            'assetId' => $assetId,
            'tempFilePath' => $tempFilePath
        ], JSON_THROW_ON_ERROR);

        $msg = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->channel->basic_publish($msg, '', $this->queue);
    }

    /**
     * Publish failed message to Dead Letter Queue
     */
    public function publishToDLQ(int $assetId, string $error, int $attempts): void
    {
        if ($this->dlq === null) {
            return; // DLQ not configured
        }

        try {
            $this->doPublishToDLQ($assetId, $error, $attempts);
        } catch (\Throwable $e) {
            // On error, disconnect and retry once
            $this->disconnect();
            $this->doPublishToDLQ($assetId, $error, $attempts);
        }
    }

    private function doPublishToDLQ(int $assetId, string $error, int $attempts): void
    {
        $this->ensureConnected();

        $payload = json_encode([
            'assetId' => $assetId,
            'error' => $error,
            'attempts' => $attempts,
            'failedAt' => (new \DateTimeImmutable())->format('c'),
        ], JSON_THROW_ON_ERROR);

        $msg = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->channel->basic_publish($msg, '', $this->dlq);
    }

    public function hasDLQ(): bool
    {
        return $this->dlq !== null;
    }
}
