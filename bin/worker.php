<?php declare(strict_types=1);

// Minimal Rabbit consumer for Nette project.
// Assumes your project has bootstrap at: app/bootstrap.php (adjust below).

require __DIR__ . '/../../../vendor/autoload.php';

// Bootstrap path can be configured via:
// 1. Command line argument: php worker.php /path/to/bootstrap.php
// 2. Environment variable: BOOTSTRAP_PATH=/path/to/bootstrap.php
// 3. Default: ../../../app/bootstrap.php
$bootstrapFile = $argv[1] ?? getenv('BOOTSTRAP_PATH') ?: __DIR__ . '/../../../app/bootstrap.php';

if (!is_file($bootstrapFile)) {
    fwrite(STDERR, "ERROR: bootstrap.php not found at {$bootstrapFile}\n");
    fwrite(STDERR, "Usage: php worker.php [/path/to/bootstrap.php]\n");
    fwrite(STDERR, "   or: BOOTSTRAP_PATH=/path/to/bootstrap.php php worker.php\n");
    exit(1);
}

$container = require $bootstrapFile;

/** @var \Doctrine\ORM\EntityManagerInterface $em */
$em = $container->getByType(\Doctrine\ORM\EntityManagerInterface::class);
/** @var \App\MediaS3\Service\MediaManager $media */
$media = $container->getByType(\App\MediaS3\Service\MediaManager::class);

$cfg = $container->getParameters()['mediaS3']['rabbit'] ?? null;
if ($cfg === null) {
    fwrite(STDERR, "ERROR: mediaS3.rabbit config missing.\n");
    exit(1);
}

$host = $cfg['host'] ?? 'rabbit';
$port = (int)($cfg['port'] ?? 5672);
$user = $cfg['user'] ?? 'guest';
$pass = $cfg['pass'] ?? 'guest';
$vhost = $cfg['vhost'] ?? '/';
$queue = $cfg['queue'] ?? 'media.process';
$prefetch = (int)($cfg['prefetch'] ?? 10);
$retryMax = (int)($cfg['retryMax'] ?? 3);

$conn = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $pass, $vhost);
$ch = $conn->channel();
$ch->queue_declare($queue, false, true, false, false);
$ch->basic_qos(null, $prefetch, null);

fwrite(STDOUT, "[media-worker] consuming queue '{$queue}' on {$host}:{$port}\n");

$callback = function(\PhpAmqpLib\Message\AMQPMessage $msg) use ($media, $em, $retryMax) {
    try {
        $data = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $assetId = (int)($data['assetId'] ?? 0);
        if ($assetId <= 0) {
            throw new \RuntimeException('Invalid assetId in message');
        }

        $ok = $media->processAsset($em, $assetId, $retryMax);
        if ($ok) {
            $msg->ack();
        } else {
            // requeue (basic_nack requeue=true)
            $msg->nack(true);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[media-worker] ERROR: {$e->getMessage()}\n");
        $msg->nack(true);
    }
};

$ch->basic_consume($queue, '', false, false, false, false, $callback);

while ($ch->is_consuming()) {
    $ch->wait();
}

$ch->close();
$conn->close();
