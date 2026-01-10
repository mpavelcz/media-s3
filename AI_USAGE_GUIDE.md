# MediaS3 - AI Usage Guide

> Stručný návod pro AI asistenty, jak používat MediaS3 knihovnu v Nette projektech

## Co to je

Knihovna pro automatické zpracování a upload obrázků na S3 s podporou:
- Automatické generování variant (thumb, medium, large...)
- Multi-formátový výstup (JPG + WEBP/AVIF)
- Asynchronní zpracování přes RabbitMQ
- Deduplikace pomocí SHA1

## Quick Start

### 1. Instalace

```bash
composer require aws/aws-sdk-php php-amqplib/php-amqplib nette/di nette/utils doctrine/orm psr/log
```

V `composer.json`:
```json
"autoload": {
  "psr-4": {
    "MediaS3\\": "packages/media-s3/src/"
  }
}
```

V `config.neon`:
```neon
extensions:
  mediaS3: MediaS3\DI\MediaS3Extension

mediaS3:
  s3:
    endpoint: 'https://eu.contabo.com'
    region: 'eu2'
    bucket: 'my-bucket'
    accessKey: %env.AWS_ACCESS_KEY_ID%
    secretKey: %env.AWS_SECRET_ACCESS_KEY%
    publicBaseUrl: 'https://cdn.domena.cz'

  rabbit:
    host: 'rabbit'
    queue: 'media.process'

  profiles:
    product:
      prefix: 'products'
      keepOriginal: true
      formats: [jpg, webp]
      variants:
        thumb:  { w: 320,  h: 320,  fit: cover }
        medium: { w: 800,  h: 800,  fit: contain }
        large:  { w: 1600, h: 1600, fit: contain }
```

### 2. DB Migrace

Spustit `migrations/001_init.sql` nebo vytvořit Doctrine migration:
- `media_asset` - hlavní tabulka pro assety
- `media_variant` - varianty (thumb, medium...)
- `media_owner_link` - univerzální N:N vztah asset ↔ owner

### 3. Základní použití

```php
use MediaS3\Service\MediaManager;
use Doctrine\ORM\EntityManagerInterface;

class ProductPresenter extends BasePresenter
{
  public function __construct(
    private MediaManager $mediaManager,
    private EntityManagerInterface $em,
  ) {}

  // Upload z formuláře
  public function handleUploadImage(FileUpload $upload, int $productId): void
  {
    $asset = $this->mediaManager->uploadLocal(
      em: $this->em,
      upload: $upload,
      profile: 'product',      // profil z configu
      ownerType: 'Product',    // typ vlastníka
      ownerId: $productId,     // ID vlastníka
      role: 'main',            // role (main/gallery/...)
      sort: 0                  // pořadí
    );

    // Asset je okamžitě ready, soubory nahrané na S3
    // $asset->getId() vrátí ID assetu
  }

  // Upload remote URL (synchronně)
  public function handleImportFromUrl(string $url, int $productId): void
  {
    $asset = $this->mediaManager->uploadRemote(
      $this->em, $url, 'product', 'Product', $productId, 'gallery', 0
    );
  }

  // Upload remote URL (asynchronně přes RabbitMQ)
  public function handleImportAsync(string $url, int $productId): void
  {
    $asset = $this->mediaManager->enqueueRemote(
      $this->em, $url, 'product', 'Product', $productId, 'gallery', 0
    );
    // Vrátí okamžitě, zpracování proběhne ve workeru
    // $asset->getStatus() === 'QUEUED'
  }
}
```

### 4. Deduplikace (automatické sdílení duplicitních obrázků)

```php
// Použít *WithDedup varianty
$asset = $this->mediaManager->uploadLocalWithDedup(
  $this->em, $upload, 'product', 'Product', $productId, 'main', 0
);

// Pokud stejný obrázek (SHA1) již existuje:
// - Vytvoří se jen nový MediaOwnerLink
// - Asset se znovu nezpracovává
// - Ušetří se storage a čas
```

## Získání obrázků v šabloně

### Vytvoření MediaUrlResolver service

```php
namespace App\Service;

use MediaS3\Entity\MediaAsset;
use MediaS3\Service\S3Storage;

class MediaUrlResolver
{
  public function __construct(private S3Storage $storage) {}

  public function getVariantUrl(MediaAsset $asset, string $variant, string $format = 'jpg'): ?string
  {
    foreach ($asset->getVariants() as $v) {
      if ($v->getVariant() === $variant && $v->getFormat() === $format) {
        return $this->storage->publicUrl($v->getS3Key());
      }
    }
    return null;
  }
}
```

Registrace v `config.neon`:
```neon
services:
  - App\Service\MediaUrlResolver
```

### Použití v Latte

```latte
{* V Product entity přidat getMediaLinks() metodu *}
{var $mainImage = $product->getMediaLinks()
  ->filter(fn($l) => $l->getRole() === 'main')
  ->first()
  ?->getAsset()}

{if $mainImage}
  <picture>
    <source srcset="{$mediaUrlResolver->getVariantUrl($mainImage, 'large', 'webp')}"
            type="image/webp">
    <img src="{$mediaUrlResolver->getVariantUrl($mainImage, 'large', 'jpg')}"
         alt="{$product->getName()}">
  </picture>
{/if}

{* Pro galerii *}
{foreach $product->getMediaLinks()
  ->filter(fn($l) => $l->getRole() === 'gallery') as $link}
  <img src="{$mediaUrlResolver->getVariantUrl($link->getAsset(), 'thumb', 'jpg')}">
{/foreach}
```

## Entity Relations

### Přidání do Product entity

```php
namespace App\Model\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use MediaS3\Entity\MediaOwnerLink;

#[ORM\Entity]
class Product
{
  #[ORM\OneToMany(
    mappedBy: 'ownerId',
    targetEntity: MediaOwnerLink::class,
    cascade: ['persist', 'remove']
  )]
  private Collection $mediaLinks;

  /** @return Collection<int, MediaOwnerLink> */
  public function getMediaLinks(): Collection
  {
    // Filtrovat jen linky pro tento produkt (ownerType + ownerId)
    return $this->mediaLinks->filter(
      fn(MediaOwnerLink $l) =>
        $l->getOwnerType() === 'Product' &&
        $l->getOwnerId() === $this->id
    );
  }

  public function getMainImage(): ?MediaAsset
  {
    return $this->getMediaLinks()
      ->filter(fn($l) => $l->getRole() === 'main')
      ->first()
      ?->getAsset();
  }

  public function getGalleryImages(): Collection
  {
    return $this->getMediaLinks()
      ->filter(fn($l) => $l->getRole() === 'gallery')
      ->map(fn($l) => $l->getAsset());
  }
}
```

**POZOR:** MediaOwnerLink má pouze `ownerId` (int), ne přímo ORM vztah na Product!
- `ownerType` = 'Product' (string)
- `ownerId` = ID produktu (int)

Toto je **univerzální polymorfní vztah** - jeden asset může patřit Product, Post, User, atd.

## Worker Setup (pro asynchronní zpracování)

### Docker Compose

```yaml
services:
  worker:
    image: your-app
    command: php packages/media-s3/bin/worker.php
    restart: unless-stopped
    depends_on:
      - rabbit
      - db
    environment:
      BOOTSTRAP_PATH: /app/app/bootstrap.php
```

### Systemd

```bash
sudo nano /etc/systemd/system/media-worker.service
```

```ini
[Unit]
Description=MediaS3 Worker
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

```bash
sudo systemctl enable media-worker
sudo systemctl start media-worker
sudo systemctl status media-worker
```

## Profily - Kdy použít jaký

```neon
profiles:
  # E-commerce produkty - velké obrázky, galerie
  product:
    prefix: 'products'
    keepOriginal: true
    maxOriginalLongEdge: 3000
    variants:
      thumb:  { w: 320,  h: 320,  fit: cover }
      card:   { w: 600,  h: 600,  fit: contain }
      large:  { w: 1600, h: 1600, fit: contain }

  # Blog příspěvky - hero + thumbnaily
  blog:
    prefix: 'blog'
    keepOriginal: true
    maxOriginalLongEdge: 2600
    variants:
      hero:  { w: 1920, h: 1080, fit: cover }
      card:  { w: 800,  h: 450,  fit: cover }
      thumb: { w: 320,  h: 180,  fit: cover }

  # Uživatelské avatary - malé, jen 1 velikost
  user:
    prefix: 'user'
    keepOriginal: false  # nepotřebujeme original
    variants:
      avatar: { w: 256, h: 256, fit: cover }

  # Homepage carousel - přesné rozměry
  carousel:
    prefix: 'carousel'
    keepOriginal: false
    variants:
      slide:  { w: 1920, h: 700, fit: cover }
      mobile: { w: 900,  h: 900, fit: cover }
```

**fit režimy:**
- `contain` - vejde se celý, zachová aspect ratio, může být menší
- `cover` - vyplní celý prostor, crop přebytku, přesně target rozměr

## Časté úkoly

### Smazání obrázku

```php
// Smazání celého assetu (včetně S3 souborů)
$this->mediaManager->deleteAsset($this->em, $assetId);

// Smazání jen linku (při deduplikaci)
$link = $this->em->getRepository(MediaOwnerLink::class)
  ->findOneBy(['ownerType' => 'Product', 'ownerId' => 123, 'role' => 'main']);
$this->em->remove($link);
$this->em->flush();
```

### Nahrazení main obrázku

```php
// Smazat starý main link
$oldLink = $this->em->getRepository(MediaOwnerLink::class)
  ->findOneBy(['ownerType' => 'Product', 'ownerId' => $id, 'role' => 'main']);
if ($oldLink) {
  $this->em->remove($oldLink);
  $this->em->flush();
}

// Upload nového
$asset = $this->mediaManager->uploadLocal(
  $this->em, $upload, 'product', 'Product', $id, 'main', 0
);
```

### Přidání do galerie

```php
// Zjistit max sort
$maxSort = $this->em->getRepository(MediaOwnerLink::class)
  ->createQueryBuilder('l')
  ->select('MAX(l.sort)')
  ->where('l.ownerType = :type AND l.ownerId = :id AND l.role = :role')
  ->setParameters(['type' => 'Product', 'id' => $id, 'role' => 'gallery'])
  ->getQuery()
  ->getSingleScalarResult() ?? -1;

// Přidat s dalším sortem
$asset = $this->mediaManager->uploadLocal(
  $this->em, $upload, 'product', 'Product', $id, 'gallery', $maxSort + 1
);
```

## Řešení problémů

### WEBP nefunguje
```bash
# Zkontrolovat podporu
php -r "print_r(gd_info());"

# Pokud WebP Support => false, nainstalovat libwebp:
# Alpine: apk add libwebp-dev
# Debian: apt-get install libwebp-dev
# Pak rebuild PHP s --with-webp
```

### Worker nezpracovává
```bash
# Kontrola worker logu
docker logs media-worker -f

# Kontrola RabbitMQ fronty
# http://localhost:15672 (guest/guest)
# Zkontrolovat frontu 'media.process'

# Manuální test workeru
docker exec -it media-worker php packages/media-s3/bin/worker.php
```

### S3 upload selhává
```bash
# Test S3 credentials
aws s3 ls s3://your-bucket --endpoint-url=https://eu.contabo.com

# V PHP zkontrolovat:
php -r "
  \$s3 = new Aws\S3\S3Client([
    'version' => 'latest',
    'region' => 'eu2',
    'endpoint' => 'https://eu.contabo.com',
    'credentials' => [
      'key' => 'YOUR_KEY',
      'secret' => 'YOUR_SECRET',
    ],
  ]);
  var_dump(\$s3->listBuckets());
"
```

## Důležité poznámky pro AI

1. **MediaOwnerLink je polymorfní vztah** - nemá přímou ORM vazbu na Product/Post/User
   - Používá `ownerType` (string) + `ownerId` (int)
   - Nelze použít `@ManyToOne` z Product na MediaOwnerLink

2. **Jeden asset může mít více linků** (deduplikace)
   - Při mazání dávat pozor na CASCADE DELETE
   - Doporučeno: mazat jen link, ne asset

3. **Worker je nutný jen pro `enqueueRemote()`**
   - `uploadLocal()` a `uploadRemote()` fungují bez RabbitMQ
   - Worker je optional, ale doporučený pro remote obrázky

4. **Formáty jsou optional podle GD podpory**
   - JPG je vždy
   - WEBP/AVIF jen pokud GD podporuje
   - Zkontrolovat `gd_info()` před použitím

5. **URL validation blokuje localhost/private IP** (SSRF protection)
   - Pro testování použít public URL nebo vypnout validaci

6. **Profily definují celé chování zpracování**
   - Různé use cases = různé profily
   - Nelze změnit varianty existujícího assetu (musel by se přegenerovat)

## Migrace dat

```php
// Import z lokálního filesystému
$files = glob('/old/uploads/products/*/*.jpg');
foreach ($files as $file) {
  preg_match('#/products/(\d+)/#', $file, $m);
  $productId = (int)$m[1];

  $upload = /* vytvořit fake FileUpload z $file */;
  $this->mediaManager->uploadLocalWithDedup(
    $this->em, $upload, 'product', 'Product', $productId, 'main', 0
  );
}

// Import z remote CDN
$urls = [/* ... */];
foreach ($urls as $url => $productId) {
  $this->mediaManager->uploadRemoteWithDedup(
    $this->em, $url, 'product', 'Product', $productId, 'gallery', 0
  );
}
```

## API Reference (jen nejdůležitější)

```php
// MediaManager
uploadLocal(em, upload, profile, ownerType, ownerId, role, sort): MediaAsset
uploadLocalWithDedup(...): MediaAsset
uploadRemote(em, url, profile, ownerType, ownerId, role, sort): MediaAsset
uploadRemoteWithDedup(...): MediaAsset
enqueueRemote(em, url, profile, ownerType, ownerId, role, sort): MediaAsset
deleteAsset(em, assetId): void
findDuplicateBySha1(em, sha1): ?MediaAsset

// MediaAsset
getId(): int
getProfile(): string
getStatus(): string  // QUEUED, PROCESSING, READY, FAILED
getVariants(): Collection<MediaVariant>
getOriginalKeyJpg(): ?string
getOriginalKeyWebp(): ?string

// MediaVariant
getVariant(): string  // 'thumb', 'medium', ...
getFormat(): string   // 'jpg', 'webp', ...
getS3Key(): string

// MediaOwnerLink
getAsset(): MediaAsset
getOwnerType(): string
getOwnerId(): int
getRole(): string
getSort(): int

// S3Storage
publicUrl(key): string
```

---

**Pro detailní dokumentaci viz `TECHNICAL_DOCUMENTATION.md`**
