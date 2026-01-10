<?php declare(strict_types=1);

namespace MediaS3\DI;

use MediaS3\Service\HttpDownloader;
use MediaS3\Service\ImageProcessorGd;
use MediaS3\Service\MediaManager;
use MediaS3\Service\MediaUrlResolver;
use MediaS3\Service\ProfileRegistry;
use MediaS3\Service\RabbitPublisher;
use MediaS3\Service\S3Storage;
use MediaS3\Service\TempFileManager;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class MediaS3Extension extends CompilerExtension
{
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'entities' => Expect::structure([
                'mediaAsset' => Expect::string()->default('MediaS3\Entity\MediaAsset'),
                'mediaOwnerLink' => Expect::string()->default('MediaS3\Entity\MediaOwnerLink'),
                'mediaVariant' => Expect::string()->default('MediaS3\Entity\MediaVariant'),
            ]),

            's3' => Expect::structure([
                'endpoint' => Expect::string()->required(),
                'region' => Expect::string()->required(),
                'bucket' => Expect::string()->required(),
                'accessKey' => Expect::string()->dynamic()->required(),
                'secretKey' => Expect::string()->dynamic()->required(),
                'publicBaseUrl' => Expect::string()->default(''),
                'cacheSeconds' => Expect::int()->min(0)->default(31536000),
            ])->required(),

            'rabbit' => Expect::structure([
                'host' => Expect::string()->default('rabbit'),
                'port' => Expect::int()->min(1)->default(5672),
                'user' => Expect::string()->default('guest'),
                'pass' => Expect::string()->default('guest'),
                'vhost' => Expect::string()->default('/'),
                'queue' => Expect::string()->default('media.process'),
                'prefetch' => Expect::int()->min(1)->default(10),
                'retryMax' => Expect::int()->min(0)->default(3),
                'dlq' => Expect::string()->nullable()->default(null),
            ]),

            'http' => Expect::structure([
                'timeoutSeconds' => Expect::int()->min(1)->default(15),
                'maxBytes' => Expect::int()->min(1)->default(15000000),
                'userAgent' => Expect::string()->default('MediaS3Bot/1.0'),
            ]),

            'temp' => Expect::structure([
                'uploadDir' => Expect::string()->dynamic()->required(),
            ])->nullable(),

            'profiles' => Expect::arrayOf(
                Expect::structure([
                    'prefix' => Expect::string()->required(),
                    'keepOriginal' => Expect::bool()->default(false),
                    'maxOriginalLongEdge' => Expect::int()->min(1)->default(3000),
                    'formats' => Expect::listOf(Expect::anyOf('jpg', 'webp', 'png', 'avif'))->default(['jpg', 'webp']),
                    'variants' => Expect::arrayOf(
                        Expect::structure([
                            'w' => Expect::int()->min(1)->required(),
                            'h' => Expect::int()->min(1)->required(),
                            'fit' => Expect::anyOf('contain', 'cover')->default('contain'),
                        ]),
                        Expect::string()
                    )->default([]),
                ]),
                Expect::string()
            )->default([]),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $cfg = $this->getConfig();

        $builder->addDefinition($this->prefix('profileRegistry'))
            ->setFactory(ProfileRegistry::class, [(array) $cfg->profiles]);

        $builder->addDefinition($this->prefix('s3Storage'))
            ->setFactory(S3Storage::class, [(array) $cfg->s3]);

        $builder->addDefinition($this->prefix('httpDownloader'))
            ->setFactory(HttpDownloader::class, [(array) $cfg->http]);

        $builder->addDefinition($this->prefix('imageProcessor'))
            ->setFactory(ImageProcessorGd::class);

        $builder->addDefinition($this->prefix('rabbitPublisher'))
            ->setFactory(RabbitPublisher::class, [(array) $cfg->rabbit]);

        // Register TempFileManager if temp config is provided
        if ($cfg->temp !== null) {
            $builder->addDefinition($this->prefix('tempFileManager'))
                ->setFactory(TempFileManager::class, [$cfg->temp->uploadDir]);
        }

        $builder->addDefinition($this->prefix('mediaUrlResolver'))
            ->setFactory(MediaUrlResolver::class, [
                $this->prefix('@s3Storage'),
            ]);

        $builder->addDefinition($this->prefix('mediaManager'))
            ->setFactory(MediaManager::class, [
                $this->prefix('@profileRegistry'),
                $this->prefix('@s3Storage'),
                $this->prefix('@imageProcessor'),
                $this->prefix('@httpDownloader'),
                $this->prefix('@rabbitPublisher'),
                $cfg->temp !== null ? $this->prefix('@tempFileManager') : null,
                null, // logger
                (array) $cfg->entities, // entity class names
            ]);
    }
}
