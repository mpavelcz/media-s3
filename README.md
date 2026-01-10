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
2) Přidej do root `composer.json` autoload:
   ```json
   {
     "autoload": {
       "psr-4": {
         "MediaS3\\": "packages/media-s3/src/"
       }
     }
   }
   ```
3) Nainstaluj závislosti:
   ```bash
   composer require aws/aws-sdk-php php-amqplib/php-amqplib nette/di nette/utils doctrine/orm psr/log
   ```
4) V `config.neon` přidej extension:
   ```neon
   extensions:
     mediaS3: MediaS3\DI\MediaS3Extension
   ```

## Konfigurace (ukázka)
Viz `config.example.neon` v balíčku.

## DB migrace
- SQL: `migrations/001_init.sql`
- nebo (pokud používáš Doctrine Migrations) přepiš to do svého migračního systému.

## RabbitMQ Worker

Worker zpracovává asynchronní úlohy (např. stahování remote obrázků).

### Spuštění workeru

```bash
# Výchozí cesta k bootstrap.php (../../../app/bootstrap.php)
php packages/media-s3/bin/worker.php

# Vlastní cesta k bootstrap.php
php packages/media-s3/bin/worker.php /custom/path/to/bootstrap.php

# Nebo přes environment variable
BOOTSTRAP_PATH=/custom/path/to/bootstrap.php php packages/media-s3/bin/worker.php
```

### Jak worker funguje

1. **Připojení k RabbitMQ** - Připojí se k frontě `media.process` (konfigurovatelné v `config.neon`)
2. **Zpracování zpráv** - Každá zpráva obsahuje `assetId` k zpracování
3. **Idempotence** - Worker používá optimistic locking (claim pattern), takže zpracování je bezpečné při více workerech
4. **Stavy assetu**:
   - `QUEUED` - Čeká na zpracování
   - `PROCESSING` - Právě se zpracovává
   - `READY` - Úspěšně zpracováno
   - `FAILED` - Selhalo (bude retryovat)

### Retry logika

- Worker automaticky retryuje failed assety až `retryMax` krát (výchozí 3x)
- Po překročení limitu:
  - Pokud je nakonfigurovaná DLQ (`dlq` v configu), zpráva se přesune do Dead Letter Queue
  - Pokud není DLQ, zpráva se pouze označí jako failed a zaloguje

### Dead Letter Queue (DLQ)

DLQ uchovává zprávy, které selhaly i po všech retrys:

```neon
mediaS3:
  rabbit:
    queue: 'media.process'
    dlq: 'media.process.dlq'  # Optional
    retryMax: 3
```

Zprávy v DLQ obsahují:
- `assetId` - ID assetu
- `error` - Chybová zpráva
- `attempts` - Počet pokusů
- `failedAt` - Timestamp selhání

### Monitoring

Worker loguje do STDOUT/STDERR:
```bash
[media-worker] consuming queue 'media.process' on rabbit:5672
[media-worker] Asset 123 moved to DLQ after 3 attempts
[media-worker] ERROR: Failed to download image...
```

### Docker / Systemd

**Docker Compose:**
```yaml
services:
  worker:
    image: your-app
    command: php packages/media-s3/bin/worker.php
    restart: unless-stopped
    depends_on:
      - rabbit
      - db
```

**Systemd:**
```ini
[Unit]
Description=Media S3 Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php packages/media-s3/bin/worker.php
Restart=always

[Install]
WantedBy=multi-user.target
```

### Škálování

Můžeš spustit více workerů paralelně - worker používá `basic_qos(prefetch=10)` pro fair dispatch.

## Použití v kódu

### Upload lokálního souboru (synchronní)
```php
$mediaManager = $container->getByType(MediaManager::class);
$em = $container->getByType(EntityManagerInterface::class);

// Bez deduplikace
$asset = $mediaManager->uploadLocal(
    $em,
    $upload, // Nette\Http\FileUpload
    'product', // profile
    'Product', // ownerType
    123, // ownerId
    'main', // role
    0 // sort
);

// S deduplikací (automaticky reuse duplicitní obrázky)
$asset = $mediaManager->uploadLocalWithDedup($em, $upload, 'product', 'Product', 123, 'main', 0);
```

### Upload remote URL (synchronní) - NOVÉ!
```php
// Stáhne remote obrázek a nahraje rovnou na S3 bez RabbitMQ
$asset = $mediaManager->uploadRemote(
    $em,
    'https://example.com/image.jpg', // sourceUrl
    'product', // profile
    'Product', // ownerType
    123, // ownerId
    'main', // role
    0 // sort
);

// S deduplikací
$asset = $mediaManager->uploadRemoteWithDedup($em, 'https://example.com/image.jpg', 'product', 'Product', 123, 'main', 0);
```

### Upload remote URL (asynchronní přes RabbitMQ)
```php
// Vytvoří záznam v DB a pošle job do RabbitMQ
// Worker pak stáhne a zpracuje obrázek asynchronně
$asset = $mediaManager->enqueueRemote($em, 'https://example.com/image.jpg', 'product', 'Product', 123, 'main', 0);
```

### Kdy použít co?

- **`uploadLocal()`** / **`uploadLocalWithDedup()`** - Pro upload z formuláře (FileUpload)
- **`uploadRemote()`** / **`uploadRemoteWithDedup()`** - Pro okamžitý download a upload remote obrázku (bez RabbitMQ)
- **`enqueueRemote()`** - Pro asynchronní zpracování remote obrázků (s RabbitMQ workerem)

## Poznámky
- WEBP se generuje jen pokud `gd_info()['WebP Support'] === true`.
- S3 objekty jsou `public-read` a mají `Cache-Control: public, max-age=31536000` (1 rok).
- Cloudflare si to pak hezky cachuje přes `cdn.domena.cz`.

## Dokumentace

Pro detailní technickou dokumentaci a použití v jiných projektech viz:

- **[AI_USAGE_GUIDE.md](AI_USAGE_GUIDE.md)** - Stručný praktický návod pro AI asistenty a vývojáře (quick start, příklady použití)
- **[TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md)** - Kompletní technická dokumentace (architektura, API, workflow, troubleshooting)
