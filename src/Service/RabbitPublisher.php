<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitPublisher
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $vhost;
    private string $queue;

    /** @param array{host?:string,port?:int,user?:string,pass?:string,vhost?:string,queue?:string} $cfg */
    public function __construct(array $cfg = [])
    {
        $this->host = (string) ($cfg['host'] ?? 'rabbit');
        $this->port = (int) ($cfg['port'] ?? 5672);
        $this->user = (string) ($cfg['user'] ?? 'guest');
        $this->pass = (string) ($cfg['pass'] ?? 'guest');
        $this->vhost = (string) ($cfg['vhost'] ?? '/');
        $this->queue = (string) ($cfg['queue'] ?? 'media.process');
    }

    public function publishProcessAsset(int $assetId): void
    {
        $conn = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass, $this->vhost);
        $ch = $conn->channel();
        $ch->queue_declare($this->queue, false, true, false, false);

        $payload = json_encode(['assetId' => $assetId], JSON_THROW_ON_ERROR);
        $msg = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $ch->basic_publish($msg, '', $this->queue);
        $ch->close();
        $conn->close();
    }
}
