<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use App\MediaS3\Entity\MediaAsset;
use App\MediaS3\Entity\MediaOwnerLink;
use App\MediaS3\Entity\MediaVariant;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Http\FileUpload;
use Nette\Utils\Random;

final class MediaManager
{
    public function __construct(
        private ProfileRegistry $profiles,
        private S3Storage $storage,
        private ImageProcessorGd $images,
        private HttpDownloader $downloader,
        private RabbitPublisher $publisher,
    ) {}

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
    ): MediaAsset {
        if (!$upload->isOk() || !$upload->isImage()) {
            throw new \RuntimeException('Upload není validní obrázek.');
        }

        $p = $this->profiles->get($profile);
        $bytes = file_get_contents($upload->getTemporaryFile());
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Nejde načíst upload bytes.');
        }

        $asset = new MediaAsset($profile, MediaAsset::SOURCE_UPLOAD, null);
        $em->persist($asset);
        $em->flush(); // získat ID

        $assetId = $asset->getId();
        $baseKey = $this->baseKey($p->prefix, $ownerType, $ownerId, $assetId);

        $this->storeOriginalAndVariants($em, $asset, $bytes, $p, $baseKey);

        $link = new MediaOwnerLink($ownerType, $ownerId, $asset, $role, $sort);
        $em->persist($link);
        $em->flush();

        return $asset;
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
    ): MediaAsset {
        $asset = new MediaAsset($profile, MediaAsset::SOURCE_REMOTE, $sourceUrl);
        $asset->setStatus(MediaAsset::STATUS_QUEUED);

        $em->persist($asset);
        $em->flush();

        $link = new MediaOwnerLink($ownerType, $ownerId, $asset, $role, $sort);
        $em->persist($link);
        $em->flush();

        $this->publisher->publishProcessAsset($asset->getId());

        return $asset;
    }

    /**
     * Worker entry – process an asset idempotently.
     * - claim QUEUED/FAILED -> PROCESSING (returns false if already processing/ready)
     */
    public function processAsset(EntityManagerInterface $em, int $assetId, int $retryMax = 3): bool
    {
        /** @var MediaAsset|null $asset */
        $asset = $em->find(MediaAsset::class, $assetId);
        if ($asset === null) {
            return true; // ack (nothing to do)
        }

        if ($asset->getStatus() === MediaAsset::STATUS_READY) {
            return true;
        }

        if ($asset->getAttempts() > $retryMax) {
            return true;
        }

        // Claim
        $q = $em->createQuery('UPDATE ' . MediaAsset::class . ' a SET a.status = :processing WHERE a.id = :id AND a.status IN (:queued, :failed)');
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

        // Refresh entity state
        $em->clear();
        $asset = $em->find(MediaAsset::class, $assetId);
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
                if ($url === null) throw new \RuntimeException('Missing source_url for remote asset');
                $dl = $this->downloader->download($url);
                $bytes = $dl['bytes'];
            } else {
                // For upload assets, worker typically not used. Mark ready if already has variants.
                // But we still allow processing if bytes not stored – in that case we cannot do anything.
                throw new \RuntimeException('Upload asset processed by worker without original bytes is not supported. Use uploadLocal() sync.');
            }

            $this->storeOriginalAndVariants($em, $asset, $bytes, $p, $baseKey);

            $asset->markReady();
            $em->persist($asset);
            $em->flush();

            return true;
        } catch (\Throwable $e) {
            $em->clear();
            $asset = $em->find(MediaAsset::class, $assetId);
            if ($asset !== null) {
                $asset->markFailed($e->getMessage());
                $asset->setStatus(MediaAsset::STATUS_FAILED);
                $em->persist($asset);
                $em->flush();
            }
            return false; // nack -> retry by rabbit (or requeue)
        }
    }

    private function baseKey(string $prefix, string $ownerType, int $ownerId, int $assetId): string
    {
        $safeOwnerType = preg_replace('~[^A-Za-z0-9_\-]~', '_', $ownerType) ?: 'Owner';
        return rtrim($prefix, '/') . '/' . $safeOwnerType . '/' . $ownerId . '/' . $assetId;
    }

    private function storeOriginalAndVariants(EntityManagerInterface $em, MediaAsset $asset, string $bytes, $profileDef, string $baseKey): void
    {
        $qualityJpg = 82;
        $qualityWebp = 80;

        // sha1 for dedupe info
        $sha1 = sha1($bytes);

        // Original
        if ($profileDef->keepOriginal) {
            $orig = $this->images->renderOriginal($bytes, $profileDef->maxOriginalLongEdge, $qualityJpg, $qualityWebp);

            $origKeyJpg = $baseKey . '/original.jpg';
            $this->storage->put($origKeyJpg, $orig['bodyJpg'], 'image/jpeg');

            $origKeyWebp = null;
            if ($orig['bodyWebp'] !== null && in_array('webp', $profileDef->formats, true)) {
                $origKeyWebp = $baseKey . '/original.webp';
                $this->storage->put($origKeyWebp, $orig['bodyWebp'], 'image/webp');
            }

            $asset->setOriginal($origKeyJpg, $origKeyWebp, $orig['w'], $orig['h'], $sha1);
        }

        // Variants
        foreach ($profileDef->variants as $variantName => $vDef) {
            foreach ($profileDef->formats as $fmt) {
                if ($fmt === 'webp' && !$this->images->isWebpSupported()) {
                    continue;
                }

                $render = $this->images->renderVariant(
                    $bytes,
                    $vDef,
                    $fmt,
                    $fmt === 'webp' ? $qualityWebp : $qualityJpg,
                    true
                );

                $key = $baseKey . '/' . $variantName . '.' . $fmt;
                $this->storage->put($key, $render['body'], $render['contentType']);

                // Idempotent upsert-like behavior: check existing by unique key
                $existing = $em->getRepository(MediaVariant::class)->findOneBy([
                    'asset' => $asset,
                    'variant' => $variantName,
                    'format' => $fmt,
                ]);

                if ($existing === null) {
                    $mv = new MediaVariant($asset, (string)$variantName, (string)$fmt, $key, $render['w'], $render['h'], strlen($render['body']));
                    $asset->addVariant($mv);
                    $em->persist($mv);
                }
            }
        }

        $em->persist($asset);
        $em->flush();
    }
}
