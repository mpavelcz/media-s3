<?php declare(strict_types=1);

namespace MediaS3\DTO;

/**
 * Result of processing an asset in worker
 */
final class ProcessAssetResult
{
    public function __construct(
        public readonly bool $success,
        public readonly bool $exceededRetries,
        public readonly ?string $error,
        public readonly int $attempts,
    ) {}

    public static function success(): self
    {
        return new self(true, false, null, 0);
    }

    public static function failed(string $error, int $attempts, bool $exceededRetries): self
    {
        return new self(false, $exceededRetries, $error, $attempts);
    }

    /**
     * For backward compatibility with worker expecting array
     * @return array{success:bool,exceededRetries:bool,error:string|null,attempts:int}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'exceededRetries' => $this->exceededRetries,
            'error' => $this->error,
            'attempts' => $this->attempts,
        ];
    }
}
