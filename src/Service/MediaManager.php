<?php declare(strict_types=1);

namespace MediaS3\Service;

use MediaS3\DTO\ProcessAssetResult;
use MediaS3\Entity\MediaAsset;
use MediaS3\Entity\MediaOwnerLink;
use MediaS3\Entity\MediaVariant;
use MediaS3\Exception\InvalidImageException;
use MediaS3\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Http\FileUpload;
use Nette\Utils\Random;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MediaManager
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    private const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB

    private LoggerInterface $logger;
    private string $mediaAssetClass;
    private string $mediaOwnerLinkClass;
    private string $mediaVariantClass;

    public function __construct(
        private ProfileRegistry $profiles,
        private S3Storage $storage,
        private ImageProcessorGd $images,
        private HttpDownloader $downloader,
        private RabbitPublisher $publisher,
        private ?TempFileManager $tempFileManager = null,
        ?LoggerInterface $logger = null,
        ?array $entityClasses = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        // Entity class names - configurable pro různé projekty
        $entityClasses = $entityClasses ?? [];
        $this->mediaAssetClass = $entityClasses['mediaAsset'] ?? MediaAsset::class;
        $this->mediaOwnerLinkClass = $entityClasses['mediaOwnerLink'] ?? MediaOwnerLink::class;
        $this->mediaVariantClass = $entityClasses['mediaVariant'] ?? MediaVariant::class;
    }

    /**
     * Sync upload (typicky z adminu) – uloží origin + varianty hned a zapíše do DB.
     */
    public function uploadLocal(
        EntityManagerInterface $em,
        FileUpload $upload,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        if (!$upload->isOk() || !$upload->isImage()) {
            throw new InvalidImageException('Upload není validní obrázek.');
        }

        $this->logger->info('Starting local upload', [
            'profile' => $profile,
            'ownerType' => $ownerType,
            'ownerId' => $ownerId,
            'role' => $role,
        ]);

        $p = $this->profiles->get($profile);
        $bytes = file_get_contents($upload->getTemporaryFile());
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Nejde načíst upload bytes.');
        }

        // Validate uploaded image
        $this->validateImageBytes($bytes);

        $em->beginTransaction();
        try {
            $assetClass = $this->mediaAssetClass;
            $asset = new $assetClass($profile, $assetClass::SOURCE_UPLOAD, null);
            $em->persist($asset);
            $em->flush(); // získat ID

            $assetId = $asset->getId();
            $baseKey = $this->baseKey($p->prefix, $ownerType, $ownerId, $assetId);

            $this->storeOriginalAndVariants($em, $asset, $bytes, $p, $baseKey);

            $asset->markReady();
            $em->persist($asset);

            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $asset, $role, $sort);
            $em->persist($link);
            $em->flush();
            $em->commit();

            $this->logger->info('Local upload completed', ['assetId' => $assetId]);

            return $asset;
        } catch (\Throwable $e) {
            $this->logger->error('Local upload failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Rollback only if transaction is still active (may auto-rollback on flush exception)
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            throw $e;
        }
    }

    /**
     * Sync upload pro remote obrázek – stáhne obrázek a uloží origin + varianty hned a zapíše do DB.
     */
    public function uploadRemote(
        EntityManagerInterface $em,
        string $sourceUrl,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        // Validate source URL
        $this->validateSourceUrl($sourceUrl);

        $this->logger->info('Starting remote upload', [
            'sourceUrl' => $sourceUrl,
            'profile' => $profile,
            'ownerType' => $ownerType,
            'ownerId' => $ownerId,
            'role' => $role,
        ]);

        $p = $this->profiles->get($profile);

        // Download remote image
        $dl = $this->downloader->download($sourceUrl);
        $bytes = $dl->bytes;

        // Validate downloaded image
        $this->validateImageBytes($bytes);

        $em->beginTransaction();
        try {
            $assetClass = $this->mediaAssetClass;
            $asset = new $assetClass($profile, $assetClass::SOURCE_REMOTE, $sourceUrl);
            $em->persist($asset);
            $em->flush(); // získat ID

            $assetId = $asset->getId();
            $baseKey = $this->baseKey($p->prefix, $ownerType, $ownerId, $assetId);

            $this->storeOriginalAndVariants($em, $asset, $bytes, $p, $baseKey);

            $asset->markReady();
            $em->persist($asset);

            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $asset, $role, $sort);
            $em->persist($link);
            $em->flush();
            $em->commit();

            $this->logger->info('Remote upload completed', ['assetId' => $assetId]);

            return $asset;
        } catch (\Throwable $e) {
            $em->rollback();
            $this->logger->error('Remote upload failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Remote obrázek – založí asset v DB, uloží link a dá job do Rabbitu.
     * Variants se udělají async workerem.
     */
    public function enqueueRemote(
        EntityManagerInterface $em,
        string $sourceUrl,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        // Validate source URL
        $this->validateSourceUrl($sourceUrl);

        $this->logger->info('Enqueuing remote image', [
            'sourceUrl' => $sourceUrl,
            'profile' => $profile,
            'ownerType' => $ownerType,
            'ownerId' => $ownerId,
        ]);

        $em->beginTransaction();
        try {
            $assetClass = $this->mediaAssetClass;
            $asset = new $assetClass($profile, $assetClass::SOURCE_REMOTE, $sourceUrl);
            $asset->setStatus(MediaAsset::STATUS_QUEUED);

            $em->persist($asset);
            $em->flush();

            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $asset, $role, $sort);
            $em->persist($link);
            $em->flush();
            $em->commit();

            $this->publisher->publishProcessAsset($asset->getId());

            $this->logger->info('Remote image enqueued', ['assetId' => $asset->getId()]);

            return $asset;
        } catch (\Throwable $e) {
            $em->rollback();
            $this->logger->error('Failed to enqueue remote image', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Worker entry – process an asset idempotently.
     * - claim QUEUED/FAILED -> PROCESSING
     * @param string|null $tempFilePath Optional path to temp file for local uploads
     * @return array{success:bool,exceededRetries:bool,error:string|null,attempts:int}
     */
    public function processAsset(EntityManagerInterface $em, int $assetId, int $retryMax = 3, ?string $tempFilePath = null): array
    {
        $this->logger->info('Processing asset', ['assetId' => $assetId]);

        /** @var MediaAsset|null $asset */
        $asset = $em->find($this->mediaAssetClass, $assetId);
        if ($asset === null) {
            $this->logger->warning('Asset not found', ['assetId' => $assetId]);
            return ProcessAssetResult::success()->toArray(); // ack (nothing to do)
        }

        if ($asset->getStatus() === MediaAsset::STATUS_READY) {
            $this->logger->info('Asset already ready', ['assetId' => $assetId]);
            return ProcessAssetResult::success()->toArray();
        }

        if ($asset->getAttempts() >= $retryMax) {
            $this->logger->warning('Asset exceeded retry limit', ['assetId' => $assetId, 'attempts' => $asset->getAttempts()]);
            return ProcessAssetResult::failed(
                $asset->getLastError() ?? 'Max retries exceeded',
                $asset->getAttempts(),
                true
            )->toArray();
        }

        // Claim
        $q = $em->createQuery('UPDATE ' . $this->mediaAssetClass . ' a SET a.status = :processing WHERE a.id = :id AND a.status IN (:queued, :failed)');
        $q->setParameters([
            'processing' => MediaAsset::STATUS_PROCESSING,
            'id' => $assetId,
            'queued' => MediaAsset::STATUS_QUEUED,
            'failed' => MediaAsset::STATUS_FAILED,
        ]);
        $affected = $q->execute();
        if ($affected !== 1) {
            return true; // someone else is processing, or ready
        }

        // Refresh entity state without clearing all entities
        $em->clear($this->mediaAssetClass);
        $asset = $em->find($this->mediaAssetClass, $assetId);
        if ($asset === null) return true;

        try {
            $p = $this->profiles->get($asset->getProfile());

            // Determine baseKey: use first owner link if exists, otherwise generic
            $ownerType = 'Unknown';
            $ownerId = 0;

            // We want deterministic folder. If you need exact owner in worker, you can fetch MediaOwnerLink.
            // Keep simple: put under prefix/_asset/{id}/...
            $baseKey = $p->prefix . '/_asset/' . $asset->getId();

            $bytes = null;
            if ($asset->getSource() === MediaAsset::SOURCE_REMOTE) {
                $url = $asset->getSourceUrl();
                if ($url === null) throw new ValidationException('Missing source_url for remote asset');

                $this->logger->debug('Downloading remote image', ['url' => $url]);
                $dl = $this->downloader->download($url);
                $bytes = $dl->bytes;

                // Validate downloaded image
                $this->validateImageBytes($bytes);

                $this->storeOriginalAndVariants($em, $asset, $bytes, $p, $baseKey);
            } elseif ($tempFilePath !== null) {
                // Process local upload from temp file
                $this->processAssetUpload($em, $asset, $tempFilePath);
                // processAssetUpload already marks asset as ready and flushes
                $this->logger->info('Asset processing completed', ['assetId' => $assetId]);
                return ProcessAssetResult::success()->toArray();
            } else {
                // For upload assets without tempFilePath
                throw new ValidationException('Upload asset processed by worker without temp file path is not supported.');
            }

            $asset->markReady();
            $em->persist($asset);
            $em->flush();

            $this->logger->info('Asset processing completed', ['assetId' => $assetId]);

            return ProcessAssetResult::success()->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Asset processing failed', [
                'assetId' => $assetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $em->clear($this->mediaAssetClass);
            $asset = $em->find($this->mediaAssetClass, $assetId);
            $attempts = 0;
            if ($asset !== null) {
                $asset->markFailed($e->getMessage());
                $asset->setStatus(MediaAsset::STATUS_FAILED);
                $em->persist($asset);
                $em->flush();
                $attempts = $asset->getAttempts();
            }

            return ProcessAssetResult::failed($e->getMessage(), $attempts, $attempts >= $retryMax)->toArray();
        }
    }

    private function baseKey(string $prefix, string $ownerType, int $ownerId, int $assetId): string
    {
        $safeOwnerType = preg_replace('~[^A-Za-z0-9_\-]~', '_', $ownerType) ?: 'Owner';
        return rtrim($prefix, '/') . '/' . $safeOwnerType . '/' . $ownerId . '/' . $assetId;
    }

    private function validateImageBytes(string $bytes): void
    {
        if (strlen($bytes) === 0) {
            throw new InvalidImageException('Image file is empty');
        }

        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            throw new InvalidImageException('Image file too large (max ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB)');
        }

        // Check MIME type using getimagesizefromstring
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new InvalidImageException('Invalid image file');
        }

        $mime = $info['mime'] ?? '';
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidImageException('Unsupported image type: ' . $mime);
        }
    }

    private function validateSourceUrl(string $url): void
    {
        // Check URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ValidationException('Invalid URL format');
        }

        // Parse URL
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new ValidationException('Invalid URL structure');
        }

        // Only allow http/https
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            throw new ValidationException('Only HTTP/HTTPS URLs are allowed');
        }

        // Block localhost and private IPs
        $host = $parsed['host'];
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new ValidationException('Localhost URLs are not allowed');
        }

        // Block private IP ranges
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new ValidationException('Private IP addresses are not allowed');
        }
    }

    /**
     * Find duplicate asset by SHA1 checksum
     */
    public function findDuplicateBySha1(EntityManagerInterface $em, string $sha1): ?object
    {
        return $em->getRepository($this->mediaAssetClass)
            ->findOneBy(['checksumSha1' => $sha1, 'status' => MediaAsset::STATUS_READY]);
    }

    /**
     * Delete asset and all its files from S3
     */
    public function deleteAsset(EntityManagerInterface $em, int $assetId): void
    {
        /** @var MediaAsset|null $asset */
        $asset = $em->find($this->mediaAssetClass, $assetId);
        if ($asset === null) {
            return; // Already deleted
        }

        // Delete all S3 files
        $keysToDelete = [];

        // Delete original files
        if ($asset->getOriginalKeyJpg() !== null) {
            $keysToDelete[] = $asset->getOriginalKeyJpg();
        }
        if ($asset->getOriginalKeyWebp() !== null) {
            $keysToDelete[] = $asset->getOriginalKeyWebp();
        }

        // Delete variant files
        foreach ($asset->getVariants() as $variant) {
            $keysToDelete[] = $variant->getS3Key();
        }

        // Delete from S3
        foreach ($keysToDelete as $key) {
            try {
                $this->storage->delete($key);
            } catch (\Throwable $e) {
                // Continue deleting other files even if one fails
            }
        }

        // Delete from database (cascade will delete variants and links)
        $em->remove($asset);
        $em->flush();
    }

    /**
     * Upload with deduplication check
     */
    public function uploadLocalWithDedup(
        EntityManagerInterface $em,
        FileUpload $upload,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        if (!$upload->isOk() || !$upload->isImage()) {
            throw new InvalidImageException('Upload není validní obrázek.');
        }

        $bytes = file_get_contents($upload->getTemporaryFile());
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Nejde načíst upload bytes.');
        }

        // Validate uploaded image
        $this->validateImageBytes($bytes);

        // Check for duplicate
        $sha1 = sha1($bytes);
        $existing = $this->findDuplicateBySha1($em, $sha1);

        if ($existing !== null) {
            $this->logger->info('Reusing existing asset (dedup)', [
                'existingAssetId' => $existing->getId(),
                'sha1' => $sha1,
            ]);
            // Reuse existing asset, just create new link
            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $existing, $role, $sort);
            $em->persist($link);
            $em->flush();
            return $existing;
        }

        // No duplicate, proceed with normal upload
        return $this->uploadLocal($em, $upload, $profile, $ownerType, $ownerId, $role, $sort);
    }

    /**
     * Async upload (lokální soubor) – vytvoří asset se statusem QUEUED, uloží soubor do temp,
     * publikuje zprávu do RabbitMQ. Zpracování (upload do S3) probíhá workerem na pozadí.
     */
    public function uploadLocalAsync(
        EntityManagerInterface $em,
        FileUpload $upload,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        if ($this->tempFileManager === null) {
            throw new \RuntimeException('TempFileManager is required for async uploads');
        }

        if (!$upload->isOk() || !$upload->isImage()) {
            throw new InvalidImageException('Upload není validní obrázek.');
        }

        $this->logger->info('Starting async local upload', [
            'profile' => $profile,
            'ownerType' => $ownerType,
            'ownerId' => $ownerId,
            'role' => $role,
        ]);

        // Validate image before saving to temp
        $bytes = file_get_contents($upload->getTemporaryFile());
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Nejde načíst upload bytes.');
        }
        $this->validateImageBytes($bytes);

        $em->beginTransaction();
        try {
            // Save to temp directory
            $tempPath = $this->tempFileManager->saveTempFile($upload);

            // Create MediaAsset with QUEUED status
            $assetClass = $this->mediaAssetClass;
            $asset = new $assetClass($profile, $assetClass::SOURCE_UPLOAD, null);
            $asset->setStatus(MediaAsset::STATUS_QUEUED);
            $em->persist($asset);
            $em->flush(); // get ID

            // Create MediaOwnerLink
            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $asset, $role, $sort);
            $em->persist($link);
            $em->flush();
            $em->commit();

            // Publish to RabbitMQ
            $this->publisher->publishProcessAssetWithFile($asset->getId(), $tempPath);

            $this->logger->info('Async upload queued', ['assetId' => $asset->getId(), 'tempPath' => $tempPath]);

            return $asset;
        } catch (\Throwable $e) {
            $this->logger->error('Async upload failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            throw $e;
        }
    }

    /**
     * Async upload s deduplikací – kontroluje SHA1, pokud existuje vrátí existující asset,
     * jinak vytvoří nový a zařadí do fronty pro async zpracování.
     */
    public function uploadLocalWithDedupAsync(
        EntityManagerInterface $em,
        FileUpload $upload,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        if (!$upload->isOk() || !$upload->isImage()) {
            throw new InvalidImageException('Upload není validní obrázek.');
        }

        $bytes = file_get_contents($upload->getTemporaryFile());
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Nejde načíst upload bytes.');
        }

        // Validate uploaded image
        $this->validateImageBytes($bytes);

        // Check for duplicate
        $sha1 = sha1($bytes);
        $existing = $this->findDuplicateBySha1($em, $sha1);

        if ($existing !== null) {
            $this->logger->info('Reusing existing asset (async dedup)', [
                'existingAssetId' => $existing->getId(),
                'sha1' => $sha1,
            ]);
            // Reuse existing asset, just create new link
            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $existing, $role, $sort);
            $em->persist($link);
            $em->flush();
            return $existing;
        }

        // No duplicate, proceed with async upload
        return $this->uploadLocalAsync($em, $upload, $profile, $ownerType, $ownerId, $role, $sort);
    }

    /**
     * Zpracuje asset z lokálního uploadu (voláno workerem).
     * Načte soubor z temp adresáře, nahraje do S3 a vytvoří varianty.
     */
    private function processAssetUpload(
        EntityManagerInterface $em,
        object $asset,
        string $tempFilePath
    ): void {
        if ($this->tempFileManager === null) {
            throw new \RuntimeException('TempFileManager is required for processing uploads');
        }

        $this->logger->info('Processing upload from temp file', [
            'assetId' => $asset->getId(),
            'tempFilePath' => $tempFilePath
        ]);

        // Check temp file exists
        if (!file_exists($tempFilePath)) {
            throw new \RuntimeException('Temporary file not found: ' . $tempFilePath);
        }

        // Read file bytes
        $bytes = file_get_contents($tempFilePath);
        if ($bytes === false || $bytes === '') {
            throw new InvalidImageException('Failed to read temp file or file is empty');
        }

        // Validate image
        $this->validateImageBytes($bytes);

        // Get profile definition
        $p = $this->profiles->get($asset->getProfile());

        // Find MediaOwnerLink to determine ownerType/ownerId for baseKey
        /** @var MediaOwnerLink|null $link */
        $link = $em->getRepository($this->mediaOwnerLinkClass)
            ->findOneBy(['asset' => $asset]);

        if ($link === null) {
            // Fallback: use generic path
            $baseKey = $p->prefix . '/_asset/' . $asset->getId();
        } else {
            // Use owner-based path
            $ownerType = $link->getOwnerType();
            $ownerId = $link->getOwnerId();
            $baseKey = $this->baseKey($p->prefix, $ownerType, $ownerId, $asset->getId());
        }

        // Store original and variants to S3
        $this->storeOriginalAndVariants($em, $asset, $bytes, $p, $baseKey);

        // Mark asset as ready
        $asset->markReady();
        $em->persist($asset);
        $em->flush();

        // Delete temp file
        $this->tempFileManager->deleteTempFile($tempFilePath);

        $this->logger->info('Upload processing completed', ['assetId' => $asset->getId()]);
    }

    /**
     * Remote upload with deduplication check
     */
    public function uploadRemoteWithDedup(
        EntityManagerInterface $em,
        string $sourceUrl,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        // Validate source URL
        $this->validateSourceUrl($sourceUrl);

        // Download remote image
        $dl = $this->downloader->download($sourceUrl);
        $bytes = $dl->bytes;

        // Validate downloaded image
        $this->validateImageBytes($bytes);

        // Check for duplicate
        $sha1 = sha1($bytes);
        $existing = $this->findDuplicateBySha1($em, $sha1);

        if ($existing !== null) {
            $this->logger->info('Reusing existing asset (dedup)', [
                'existingAssetId' => $existing->getId(),
                'sha1' => $sha1,
                'sourceUrl' => $sourceUrl,
            ]);
            // Reuse existing asset, just create new link
            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $existing, $role, $sort);
            $em->persist($link);
            $em->flush();
            return $existing;
        }

        // No duplicate, proceed with normal remote upload
        return $this->uploadRemote($em, $sourceUrl, $profile, $ownerType, $ownerId, $role, $sort);
    }

    /**
     * Async remote upload s deduplikací - stáhne obrázek, zkontroluje duplicitu,
     * a pokud není duplikát, uloží do temp a zařadí do fronty pro async zpracování.
     */
    public function enqueueRemoteWithDedup(
        EntityManagerInterface $em,
        string $sourceUrl,
        string $profile,
        string $ownerType,
        int $ownerId,
        string $role,
        int $sort = 0
    ): object {
        if ($this->tempFileManager === null) {
            throw new \RuntimeException('TempFileManager is required for async remote uploads with dedup');
        }

        // Validate source URL
        $this->validateSourceUrl($sourceUrl);

        $this->logger->info('Starting async remote upload with dedup', [
            'sourceUrl' => $sourceUrl,
            'profile' => $profile,
            'ownerType' => $ownerType,
            'ownerId' => $ownerId,
            'role' => $role,
        ]);

        // Download remote image (sync - needed for SHA1 check)
        $dl = $this->downloader->download($sourceUrl);
        $bytes = $dl->bytes;

        // Validate downloaded image
        $this->validateImageBytes($bytes);

        // Check for duplicate
        $sha1 = sha1($bytes);
        $existing = $this->findDuplicateBySha1($em, $sha1);

        if ($existing !== null) {
            $this->logger->info('Reusing existing asset (async dedup)', [
                'existingAssetId' => $existing->getId(),
                'sha1' => $sha1,
                'sourceUrl' => $sourceUrl,
            ]);
            // Reuse existing asset, just create new link
            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $existing, $role, $sort);
            $em->persist($link);
            $em->flush();
            return $existing;
        }

        // No duplicate - save to temp and enqueue for async processing
        $tempPath = $this->tempFileManager->saveTempBytes($bytes, 'img');

        $em->beginTransaction();
        try {
            $assetClass = $this->mediaAssetClass;
            $asset = new $assetClass($profile, $assetClass::SOURCE_REMOTE, $sourceUrl);
            $asset->setStatus(MediaAsset::STATUS_QUEUED);
            $em->persist($asset);
            $em->flush();

            $linkClass = $this->mediaOwnerLinkClass;
            $link = new $linkClass($ownerType, $ownerId, $asset, $role, $sort);
            $em->persist($link);
            $em->flush();
            $em->commit();

            // Publish to RabbitMQ with temp file path
            $this->publisher->publishProcessAssetWithFile($asset->getId(), $tempPath);

            $this->logger->info('Async remote upload enqueued', [
                'assetId' => $asset->getId(),
                'tempPath' => $tempPath,
            ]);

            return $asset;
        } catch (\Throwable $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            // Clean up temp file on failure
            $this->tempFileManager->deleteTempFile($tempPath);

            $this->logger->error('Async remote upload failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function storeOriginalAndVariants(EntityManagerInterface $em, object $asset, string $bytes, $profileDef, string $baseKey): void
    {
        $qualityJpg = 82;
        $qualityWebp = 80;

        // sha1 for dedupe info
        $sha1 = sha1($bytes);

        // Collect all files to upload for batch async upload
        $filesToUpload = [];

        // Original
        $origKeyJpg = null;
        $origKeyWebp = null;
        $origW = 0;
        $origH = 0;

        if ($profileDef->keepOriginal) {
            $orig = $this->images->renderOriginal($bytes, $profileDef->maxOriginalLongEdge, $qualityJpg, $qualityWebp);

            $origKeyJpg = $baseKey . '/original.jpg';
            $filesToUpload[] = [
                'key' => $origKeyJpg,
                'body' => $orig->bodyJpg,
                'contentType' => 'image/jpeg',
            ];

            if ($orig->bodyWebp !== null && in_array('webp', $profileDef->formats, true)) {
                $origKeyWebp = $baseKey . '/original.webp';
                $filesToUpload[] = [
                    'key' => $origKeyWebp,
                    'body' => $orig->bodyWebp,
                    'contentType' => 'image/webp',
                ];
            }

            if ($orig->bodyAvif !== null && in_array('avif', $profileDef->formats, true)) {
                $filesToUpload[] = [
                    'key' => $baseKey . '/original.avif',
                    'body' => $orig->bodyAvif,
                    'contentType' => 'image/avif',
                ];
            }

            if ($orig->bodyPng !== null && in_array('png', $profileDef->formats, true)) {
                $filesToUpload[] = [
                    'key' => $baseKey . '/original.png',
                    'body' => $orig->bodyPng,
                    'contentType' => 'image/png',
                ];
            }

            $origW = $orig->w;
            $origH = $orig->h;
        }

        // Load all existing variants at once to prevent N+1 queries
        $existingVariants = $em->getRepository($this->mediaVariantClass)->findBy(['asset' => $asset]);
        $existingMap = [];
        foreach ($existingVariants as $v) {
            $key = $v->getVariant() . '_' . $v->getFormat();
            $existingMap[$key] = $v;
        }

        // Render all variants and collect for batch upload
        $variantsToCreate = [];
        foreach ($profileDef->variants as $variantName => $vDef) {
            foreach ($profileDef->formats as $fmt) {
                // Skip unsupported formats
                if ($fmt === 'webp' && !$this->images->isWebpSupported()) {
                    continue;
                }
                if ($fmt === 'avif' && !$this->images->isAvifSupported()) {
                    continue;
                }
                if ($fmt === 'png' && !$this->images->isPngSupported()) {
                    continue;
                }

                $quality = match($fmt) {
                    'webp', 'avif', 'png' => $qualityWebp,
                    default => $qualityJpg,
                };

                $render = $this->images->renderVariant(
                    $bytes,
                    $vDef,
                    $fmt,
                    $quality,
                    true
                );

                $key = $baseKey . '/' . $variantName . '.' . $fmt;

                $filesToUpload[] = [
                    'key' => $key,
                    'body' => $render->body,
                    'contentType' => $render->contentType,
                ];

                // Check if variant already exists using our preloaded map
                $lookupKey = $variantName . '_' . $fmt;
                if (!isset($existingMap[$lookupKey])) {
                    $variantsToCreate[] = [
                        'variantName' => $variantName,
                        'format' => $fmt,
                        'key' => $key,
                        'w' => $render->w,
                        'h' => $render->h,
                        'size' => strlen($render->body),
                    ];
                }
            }
        }

        // Upload all files in parallel (async batch upload)
        $this->storage->putMultiple($filesToUpload, 5);

        // After successful upload, update database
        if ($profileDef->keepOriginal && $origKeyJpg !== null) {
            $asset->setOriginal($origKeyJpg, $origKeyWebp, $origW, $origH, $sha1);
        }

        foreach ($variantsToCreate as $varData) {
            $variantClass = $this->mediaVariantClass;
            $mv = new $variantClass(
                $asset,
                (string)$varData['variantName'],
                (string)$varData['format'],
                $varData['key'],
                $varData['w'],
                $varData['h'],
                $varData['size']
            );
            $asset->addVariant($mv);
            $em->persist($mv);
        }

        $em->persist($asset);
        $em->flush();
    }

    /**
     * Vrátí počet selhaných assetů
     */
    public function countFailedAssets(EntityManagerInterface $em): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from($this->mediaAssetClass, 'a')
            ->where('a.status = :status')
            ->setParameter('status', MediaAsset::STATUS_FAILED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vrátí selhané assety starší než zadaný počet hodin
     * @return object[]
     */
    public function findFailedAssetsOlderThan(EntityManagerInterface $em, int $hours): array
    {
        $cutoff = new \DateTimeImmutable("-{$hours} hours");

        return $em->createQueryBuilder()
            ->select('a')
            ->from($this->mediaAssetClass, 'a')
            ->where('a.status = :status')
            ->andWhere('a.createdAt < :cutoff')
            ->setParameter('status', MediaAsset::STATUS_FAILED)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Smaže selhané assety starší než zadaný počet hodin
     * @return int Počet smazaných assetů
     */
    public function deleteFailedAssetsOlderThan(EntityManagerInterface $em, int $hours): int
    {
        $assets = $this->findFailedAssetsOlderThan($em, $hours);
        $count = 0;

        foreach ($assets as $asset) {
            $this->deleteAsset($em, $asset->getId());
            $count++;
        }

        $this->logger->info('Deleted old failed assets', ['count' => $count, 'olderThanHours' => $hours]);

        return $count;
    }
}
