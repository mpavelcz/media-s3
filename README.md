# Media S3 (Contabo/AWS S3) + GD + RabbitMQ worker for Nette + Doctrine

Tento balíček přidává:
- upload obrázků (Nette FileUpload) podle **profilu** (product/blog/gallery/carousel/user…)
- generování více velikostí (**downscale-only**, bez zvětšování)
- ukládání do S3 kompatibilního úložiště (Contabo S3) jako **JPG** + (pokud GD umí) **WEBP**
- ukládání metadat do DB přes Doctrine (entities + migrace)
- asynchronní zpracování přes RabbitMQ (worker v samostatném kontejneru) zapisuje přímo do DB (bez API)
- univerzální **link tabulku** (ownerType/ownerId/role/sort) pro produkt/blog/galerii…

## Instalace
1) Zkopíruj složku `packages/media-s3` do projektu.
2) Přidej do root `composer.json` autoload (pokud ještě nemáš psr-4 pro `App\`):
   - tento balíček používá namespace `App\MediaS3\...` (můžeš přejmenovat)
3) Nainstaluj závislosti:
   ```bash
   composer require aws/aws-sdk-php php-amqplib/php-amqplib nette/di nette/utils doctrine/orm
   ```
4) V `config.neon` přidej extension:
   ```neon
   extensions:
     mediaS3: App\MediaS3\DI\MediaS3Extension
   ```

## Konfigurace (ukázka)
Viz `config.example.neon` v balíčku.

## DB migrace
- SQL: `migrations/001_init.sql`
- nebo (pokud používáš Doctrine Migrations) přepiš to do svého migračního systému.

## Worker
Spusť jako separátní container proces:
```bash
php packages/media-s3/bin/worker.php
```

Worker:
- čte zprávy z Rabbitu (queue `media.process`)
- **idempotentně** generuje origin + varianty, nahrává do S3, zapisuje do DB
- používá stav `media_asset.status` (QUEUED/PROCESSING/READY/FAILED)

## Poznámky
- WEBP se generuje jen pokud `gd_info()['WebP Support'] === true`.
- S3 objekty jsou `public-read` a mají `Cache-Control: public, max-age=31536000` (1 rok).
- Cloudflare si to pak hezky cachuje přes `cdn.domena.cz`.

# media-s3
