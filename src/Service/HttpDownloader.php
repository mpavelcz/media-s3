<?php declare(strict_types=1);

namespace App\MediaS3\Service;

final class HttpDownloader
{
    private int $timeoutSeconds;
    private int $maxBytes;
    private string $userAgent;

    /** @param array{timeoutSeconds?:int,maxBytes?:int,userAgent?:string} $cfg */
    public function __construct(array $cfg = [])
    {
        $this->timeoutSeconds = (int) ($cfg['timeoutSeconds'] ?? 15);
        $this->maxBytes = (int) ($cfg['maxBytes'] ?? 15000000);
        $this->userAgent = (string) ($cfg['userAgent'] ?? 'MediaS3Bot/1.0');
    }

    /** @return array{bytes:string, mime:string} */
    public function download(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $buf = '';
        $mime = 'application/octet-stream';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buf) {
                $buf .= $data;
                if (strlen($buf) > $this->maxBytes) {
                    return 0; // abort
                }
                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($ct !== '') {
            $mime = trim(explode(';', $ct)[0]);
        }
        curl_close($ch);

        if ($ok === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP download failed ({$code}): {$err}");
        }
        if (strlen($buf) === 0) {
            throw new \RuntimeException('Downloaded empty body');
        }

        return ['bytes' => $buf, 'mime' => $mime];
    }
}
