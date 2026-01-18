# MediaS3 - Technická dokumentace pro AI/vývojáře

## Přehled komponenty

MediaS3 je komplexní knihovna pro správu a zpracování obrázků v Nette + Doctrine aplikacích s těmito klíčovými vlastnostmi:

- **Upload a zpracování obrázků** - lokální upload (FileUpload) i stahování z remote URL
- **Automatické generování variant** - podle konfigurovatelných profilů (thumb, medium, large...)
- **Multi-formátový výstup** - JPG (vždy) + WEBP/AVIF/PNG (volitelně, pokud GD podporuje)
- **S3 kompatibilní storage** - upload na Contabo S3, AWS S3 nebo jakékoliv S3-compatible úložiště
- **Asynchronní zpracování** - přes RabbitMQ worker pro remote obrázky
- **Deduplikace** - automatické sdílení identických obrázků pomocí SHA1
- **Univerzální linking** - flexibilní vztahy mezi obrázky a vlastníky (Product, Post, User...)
- **Retry a DLQ** - robustní zpracování chyb s Dead Letter Queue

## Architektura systému

### Hlavní komponenty

```
┌─────────────────────────────────────────────────────────────────┐
│                        MediaManager                             │
│  (Orchestrátor - řídí celý workflow uploadu a zpracování)       │
└────────────┬────────────────────────────────────────────────────┘
             │
    ┌────────┴────────┬─────────┬─────────────┬──────────────┐
    │                 │         │             │              │
┌───▼────┐    ┌──────▼──┐  ┌───▼────┐  ┌─────▼─────┐  ┌───▼────┐
│Profile │    │  Image  │  │   S3   │  │  Rabbit   │  │  Http  │
│Registry│    │Processor│  │Storage │  │ Publisher │  │Download│
│        │    │   (GD)  │  │        │  │           │  │        │
└────────┘    └─────────┘  └────────┘  └───────────┘  └────────┘
                                              │
                                              ▼
                                        ┌──────────┐
                                        │ RabbitMQ │
                                        │  Queue   │
                                        └────┬─────┘
                                             │
                                        ┌────▼─────┐
                                        │  Worker  │
                                        │  Process │
                                        └──────────┘
```

### Datový model (DB Entity)

```
MediaAsset (media_asset)
├─ id: int
├─ profile: string (např. 'product', 'blog')
├─ source: 'upload' | 'remote'
├─ sourceUrl: string? (URL pro remote obrázky)
├─ originalKeyJpg: string? (S3 klíč pro JPG original)
├─ originalKeyWebp: string? (S3 klíč pro WEBP original)
├─ originalWidth/Height: int?
├─ checksumSha1: string? (pro deduplikaci)
├─ status: 'QUEUED' | 'PROCESSING' | 'READY' | 'FAILED'
├─ attempts: int (počet pokusů o zpracování)
├─ lastError: string?
├─ createdAt/updatedAt: DateTimeImmutable
└─ variants: Collection<MediaVariant>

MediaVariant (media_variant)
├─ id: int
├─ asset: MediaAsset (FK)
├─ variant: string (např. 'thumb', 'medium', 'large')
├─ format: 'jpg' | 'webp' | 'png' | 'avif'
├─ s3Key: string (cesta na S3)
├─ width/height: int
├─ bytes: int (velikost souboru)
└─ createdAt: DateTimeImmutable

MediaOwnerLink (media_owner_link)
├─ id: int
├─ ownerType: string (např. 'Product', 'Post', 'User')
├─ ownerId: int (ID vlastníka)
├─ asset: MediaAsset (FK, CASCADE DELETE)
├─ role: string (např. 'main', 'gallery', 'slide')
├─ sort: int (pořadí)
└─ createdAt: DateTimeImmutable
```

**Vztahy:**
- `MediaAsset` 1:N `MediaVariant` (CASCADE)
- `MediaAsset` 1:N `MediaOwnerLink` (CASCADE)
- Jeden asset může mít více linků (sdílení mezi vlastníky)
- Jeden vlastník může mít více assetů různých rolí

## Workflow zpracování obrázků

### 1. Synchronní upload lokálního souboru

```php
MediaManager::uploadLocal(em, FileUpload, profile, ownerType, ownerId, role, sort)
```

**Proces:**
1. Validace uploadu (isOk, isImage, velikost, MIME type)
2. Načtení bytes z temporary file
3. Začátek DB transakce
4. Vytvoření `MediaAsset` entity (status = QUEUED, ale hned se zpracuje)
5. Flush DB → získání asset ID
6. **Zpracování obrázku:**
   - Pokud `keepOriginal=true`:
     - Resize original na `maxOriginalLongEdge` (downscale only)
     - Generování JPG (vždy)
     - Generování WEBP/AVIF/PNG (pokud podporováno a v `formats`)
   - Pro každou variantu z profilu:
     - Resize podle `w`, `h`, `fit` (cover/contain)
     - Generování všech formátů (JPG + další dle konfigurace)
     - Vytvoření `MediaVariant` entity
7. **Batch upload na S3:**
   - Všechny soubory (original + všechny varianty) se uploadují paralelně pomocí `S3Storage::putMultiple()`
   - Použití AWS CommandPool s concurrency=5
   - Atomic rollback při selhání (smaže částečně uploadnuté soubory)
8. Uložení SHA1 checksum pro deduplikaci
9. Vytvoření `MediaOwnerLink`
10. Commit transakce
11. Asset má status = READY (implicitně po úspěšném zpracování)

**S3 struktura:**
```
{prefix}/{ownerType}/{ownerId}/{assetId}/original.jpg
{prefix}/{ownerType}/{ownerId}/{assetId}/original.webp
{prefix}/{ownerType}/{ownerId}/{assetId}/thumb.jpg
{prefix}/{ownerType}/{ownerId}/{assetId}/thumb.webp
{prefix}/{ownerType}/{ownerId}/{assetId}/medium.jpg
...
```

Příklad: `products/Product/123/456/thumb.webp`

**Volitelný ownerType:**
Pokud je `ownerType` prázdný string nebo `"_"`, přeskočí se v cestě:
```
{prefix}/{ownerId}/{assetId}/original.jpg   # ownerType = '' nebo '_'
```
Příklad: `gallery/123/456/thumb.webp` (bez ownerType)

### 2. Synchronní upload remote obrázku

```php
MediaManager::uploadRemote(em, sourceUrl, profile, ownerType, ownerId, role, sort)
```

**Proces:**
1. Validace URL (format, scheme, blokování localhost/private IP)
2. **Download obrázku:**
   - HTTP request přes `HttpDownloader` (cURL)
   - Timeout: default 15s
   - Max size: default 15MB (streaming s abort při překročení)
   - Sledování redirects (max 5)
3. Validace stažených bytes (stejně jako u lokálního uploadu)
4. **Zbytek identický s uploadLocal** (vytvoření asset, zpracování, upload na S3)

**Bezpečnostní validace URL:**
- Pouze HTTP/HTTPS protokoly
- Blokování localhost (127.0.0.1, ::1)
- Blokování private IP ranges (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
- DNS resolution check pomocí `gethostbyname()` + `FILTER_FLAG_NO_PRIV_RANGE`

### 3. Asynchronní zpracování remote obrázku

```php
MediaManager::enqueueRemote(em, sourceUrl, profile, ownerType, ownerId, role, sort)
```

**Proces:**
1. Validace URL
2. Začátek DB transakce
3. Vytvoření `MediaAsset` entity se **status = QUEUED**
4. Vytvoření `MediaOwnerLink`
5. Commit transakce
6. **Publikace zprávy do RabbitMQ:**
   ```json
   {"assetId": 123}
   ```
7. Okamžitý return (bez čekání na zpracování)

**Worker proces:**
- Běží jako samostatný daemon/container
- Konzumuje frontu `media.process`
- Pro každou zprávu:
  1. Načte asset z DB
  2. **Optimistic locking (claim pattern):**
     ```sql
     UPDATE media_asset
     SET status = 'PROCESSING'
     WHERE id = ? AND status IN ('QUEUED', 'FAILED')
     ```
  3. Pokud affected = 0 → jiný worker už zpracovává nebo asset je READY → ACK a skip
  4. Download remote URL pomocí `HttpDownloader`
  5. Zpracování obrázku (stejně jako synchronní verze)
  6. Upload na S3 (batch upload)
  7. Nastavení status = READY
  8. ACK zprávy

**Retry logika:**
- Při chybě: increment `attempts`, nastavení `lastError`, status = FAILED
- Worker automaticky requeue zprávu (NACK s requeue=true)
- Maximální počet pokusů: `retryMax` (default 3)
- Po překročení limitu:
  - Pokud je DLQ konfigurace → přesun do Dead Letter Queue + ACK
  - Pokud není DLQ → pouze log + ACK (zpráva se zahodí)

**Dead Letter Queue (DLQ):**
```json
{
  "assetId": 123,
  "error": "HTTP download failed (404): ...",
  "attempts": 3,
  "failedAt": "2024-01-15T10:30:00+00:00"
}
```

DLQ zprávy je potřeba zpracovat manuálně (admin panel, monitoring...).

## Konfigurace profilů

Profil definuje, jak se mají obrázky zpracovávat pro daný use case.

### Struktura profilu

```neon
mediaS3:
  profiles:
    product:                        # název profilu
      prefix: 'products'            # prefix v S3 (složka)
      keepOriginal: true            # uchovávat original?
      maxOriginalLongEdge: 3000     # max delší strana originalu (px)
      formats: [jpg, webp]          # formáty výstupu
      variants:                     # definice variant
        thumb:
          w: 320                    # cílová šířka
          h: 320                    # cílová výška
          fit: cover                # cover | contain
        medium:
          w: 800
          h: 800
          fit: contain
        large:
          w: 1600
          h: 1600
          fit: contain
```

### Fit režimy

**contain** (default):
- Obrázek se vejde celý do target rozměrů
- Zachovává aspect ratio
- Žádný crop
- Může být menší než target (pokud původní obrázek je menší)

**cover**:
- Obrázek vyplní celý target rozměr
- Zachovává aspect ratio
- Cropne přebytečnou část (střed)
- Vždy přesně target rozměr

### Downscale-only logika

Knihovna **NIKDY nezvetšuje obrázky**. Pokud je původní obrázek menší než target:
- `noUpscale=true` (default): zachová původní rozměr (menší než target)
- Pouze u `fit: cover` může dojít k přesné target velikosti, ale jen pokud aspoň jedna strana je větší

Příklad:
```
Původní: 500x300
Target (contain): 800x800
Výsledek: 500x300 (nezvetšeno)

Původní: 500x300
Target (cover): 320x320
Výsledek: 320x320 (crop z 533x320 downscale)
```

## Service API

### MediaManager

**Hlavní metody:**

```php
// Synchronní upload lokálního souboru
uploadLocal(
  EntityManagerInterface $em,
  FileUpload $upload,
  string $profile,           // např. 'product'
  string $ownerType,         // např. 'Product'
  int $ownerId,              // např. 123
  string $role,              // např. 'main', 'gallery'
  int $sort = 0              // pořadí (pro galerie)
): MediaAsset

// Synchronní upload remote obrázku (bez RabbitMQ)
uploadRemote(
  EntityManagerInterface $em,
  string $sourceUrl,
  string $profile,
  string $ownerType,
  int $ownerId,
  string $role,
  int $sort = 0
): MediaAsset

// Asynchronní zpracování remote obrázku (přes RabbitMQ)
enqueueRemote(
  EntityManagerInterface $em,
  string $sourceUrl,
  string $profile,
  string $ownerType,
  int $ownerId,
  string $role,
  int $sort = 0
): MediaAsset

// S deduplikací (automaticky reuse duplicitních obrázků)
// + kontrola duplicitního linku - nevytváří duplicitní linky při opakovaném importu
uploadLocalWithDedup(...): MediaAsset
uploadRemoteWithDedup(...): MediaAsset
enqueueRemoteWithDedup(...): MediaAsset  // async verze s deduplikací

// Bulk import více URL s deduplikací a progress callbackem
importFromUrls(
  EntityManagerInterface $em,
  array $urls,                     // pole URL k importu
  string $profile,
  string $ownerType,               // použijte '_' pro flat strukturu
  int $ownerId,
  string $role = 'gallery',
  bool $async = false,             // true = přes RabbitMQ
  ?callable $onProgress = null     // callback(int $current, int $total, string $url, ?object $asset, ?\Throwable $error)
): array{imported: int, skipped: int, failed: int, assets: object[]}

// Hledání duplicitního assetu podle SHA1
findDuplicateBySha1(
  EntityManagerInterface $em,
  string $sha1
): ?MediaAsset

// Smazání assetu včetně všech souborů na S3
deleteAsset(
  EntityManagerInterface $em,
  int $assetId
): void

// Worker metoda - zpracování assetu (idempotentní)
processAsset(
  EntityManagerInterface $em,
  int $assetId,
  int $retryMax = 3
): array{success:bool, exceededRetries:bool, error:string|null, attempts:int}
```

**Kdy použít jakou metodu:**

| Metoda | Use case | Blocking? | Vyžaduje RabbitMQ? |
|--------|----------|-----------|-------------------|
| `uploadLocal` | Upload z formuláře, admin | Ano | Ne |
| `uploadLocalWithDedup` | Upload s automatickou deduplikací | Ano | Ne |
| `uploadRemote` | Okamžitý download+upload remote obrázku | Ano | Ne |
| `uploadRemoteWithDedup` | Remote upload s deduplikací | Ano | Ne |
| `enqueueRemote` | Asynchronní zpracování remote obrázků | Ne | Ano |

### S3Storage

```php
// Synchronní upload jednoho souboru
put(string $key, string $body, string $contentType): void

// Paralelní batch upload (až 5 souborů současně)
putMultiple(
  array $files,              // [{key, body, contentType}, ...]
  int $concurrency = 5
): void

// Smazání souboru
delete(string $key): void

// Získání public URL
publicUrl(string $key): string
```

**Batch upload vlastnosti:**
- Používá AWS CommandPool pro asynchronní paralelní upload
- Atomic rollback: při selhání jednoho uploadu smaže všechny již uploadnuté
- ACL: `public-read`
- Cache-Control: `public, max-age={cacheSeconds}` (default 1 rok)

### ImageProcessorGd

```php
// Render varianty
renderVariant(
  string $srcBytes,
  VariantDefinition $def,
  string $format,            // 'jpg' | 'webp' | 'avif' | 'png'
  int $quality,              // 0-100
  bool $noUpscale = true
): VariantRenderResult       // {body, w, h, contentType}

// Render originalu (downscale na maxLongEdge)
renderOriginal(
  string $srcBytes,
  int $maxLongEdge,
  int $qualityJpg = 82,
  int $qualityWebp = 80
): OriginalRenderResult      // {bodyJpg, bodyWebp?, bodyAvif?, bodyPng?, w, h}

// Feature detection
isWebpSupported(): bool      // gd_info()['WebP Support']
isAvifSupported(): bool      // function_exists('imageavif')
isPngSupported(): bool       // function_exists('imagepng')
```

**Memory management:**
- Před zpracováním kontrola dostupné paměti
- Odhad: `width * height * 5 bytes`
- Throw `ImageProcessingException` pokud nedostatek paměti
- Podpora `memory_limit` parsování (K/M/G suffix)

**Kvalita:**
- JPG: 0-100 (default 82)
- WEBP/AVIF: 0-100 (default 80)
- PNG: 0-9 compression level (automaticky konvertováno z 0-100)

**Alpha handling:**
- JPG: alpha flatten na bílé pozadí
- WEBP/AVIF/PNG: zachování alpha kanálu

### HtmlImageExtractor

Služba pro extrakci URL obrázků z HTML obsahu.

```php
// Extrakce s výchozími patterny (fancybox, lightbox, photoswipe, data atributy)
extract(
  string $html,
  ?string $baseUrl = null    // pro normalizaci relativních URL
): string[]

// Extrakce s vlastními regex patterny
extractWithPatterns(
  string $html,
  array $patterns,           // pole regex patternů (capture group 1 = URL)
  ?string $baseUrl = null
): string[]

// Extrakce pouze <img src="...">
extractImgSrc(
  string $html,
  ?string $baseUrl = null,
  ?string $pathFilter = null // volitelný filter cesty (např. '/gallery/')
): string[]
```

**Výchozí patterny:**
- Fancybox linky (`<a class="fancybox" href="...">`)
- Lightbox data atributy (`data-src`, `data-full`, `data-large`, `data-original`)
- PhotoSwipe (`data-pswp-src`)
- Generic lightbox (`data-lightbox`, `data-gallery`, `rel="lightbox"`)

**Normalizace URL:**
- Absolutní URL: bez změny
- Protocol-relative (`//`): přidá `https:`
- Root-relative (`/path`): přidá origin z baseUrl
- Relativní (`path`): přidá baseUrl + `/`

**Validace:**
- Pouze HTTP/HTTPS URL
- Pouze obrazové přípony: jpg, jpeg, png, webp, gif

### HttpDownloader

```php
download(string $url): DownloadResult  // {bytes, mime, size}
```

**Vlastnosti:**
- cURL s follow redirects (max 5)
- Streaming download s abort při překročení `maxBytes`
- Timeout: `timeoutSeconds` (default 15s)
- User-Agent: konfigurovatelný (default 'MediaS3Bot/1.0')
- Validace HTTP status code (200-299)

### RabbitPublisher

```php
// Publikace zprávy pro zpracování assetu
publishProcessAsset(int $assetId): void

// Publikace do Dead Letter Queue
publishToDLQ(
  int $assetId,
  string $error,
  int $attempts
): void

// Má nakonfigurovanou DLQ?
hasDLQ(): bool
```

**Vlastnosti:**
- Automatický reconnect při chybě
- Persistent messages (DELIVERY_MODE_PERSISTENT)
- Lazy connection (připojení až při první publikaci)

## Deduplikace

Deduplikace umožňuje sdílet identické obrázky mezi více vlastníky.

### Princip

1. Před uploadem se vypočítá SHA1 hash z bytes
2. Vyhledání existujícího assetu s tímto SHA1 a status=READY
3. Pokud existuje:
   - Nový asset se NEVYTVÁŘÍ
   - Vytvoří se pouze nový `MediaOwnerLink` na existující asset
   - Ušetří se: zpracování, storage, bandwidth
4. Pokud neexistuje:
   - Běžný upload + uložení SHA1 do assetu

### Použití

```php
// Automatická deduplikace při lokálním uploadu
$asset = $mediaManager->uploadLocalWithDedup(
  $em, $upload, 'product', 'Product', 123, 'main', 0
);

// Automatická deduplikace při remote uploadu
$asset = $mediaManager->uploadRemoteWithDedup(
  $em, $url, 'product', 'Product', 123, 'main', 0
);

// Manuální kontrola duplikátu
$sha1 = sha1($bytes);
$existing = $mediaManager->findDuplicateBySha1($em, $sha1);
if ($existing !== null) {
  // Reuse existing asset
}
```

### Výhody a nevýhody

**Výhody:**
- Úspora storage (jeden asset → více vlastníků)
- Rychlejší upload (bez zpracování)
- Konzistence (změna assetu = změna všude)

**Nevýhody:**
- Smazání assetu při deleteAsset() smaže pro všechny vlastníky
- Doporučení: smazat pouze `MediaOwnerLink`, ne celý asset
- Pokud potřebujete nezávislé assety, nepoužívejte deduplikaci

## Mazání assetů

### Standardní mazání

```php
$mediaManager->deleteAsset($em, $assetId);
```

**Co se stane:**
1. Načtení assetu z DB
2. Smazání všech souborů z S3:
   - `original_key_jpg`
   - `original_key_webp`
   - Všechny varianty (`variant.s3_key`)
3. Smazání assetu z DB
4. CASCADE DELETE automaticky smaže:
   - Všechny `MediaVariant`
   - Všechny `MediaOwnerLink` (pozor u deduplikace!)

### Bezpečné mazání při deduplikaci

```php
// Smazat pouze link, zachovat asset
$link = $em->getRepository(MediaOwnerLink::class)
  ->findOneBy(['ownerType' => 'Product', 'ownerId' => 123, 'role' => 'main']);
if ($link) {
  $em->remove($link);
  $em->flush();
}

// Smazat asset pouze pokud nemá žádné další linky
$asset = $em->find(MediaAsset::class, $assetId);
$linkCount = $em->getRepository(MediaOwnerLink::class)
  ->count(['asset' => $asset]);
if ($linkCount === 0) {
  $mediaManager->deleteAsset($em, $assetId);
}
```

## Získání URL variant

Příklad pomocí custom service `MediaUrlResolver`:

```php
class MediaUrlResolver
{
  public function __construct(private S3Storage $storage) {}

  public function getVariantUrl(MediaAsset $asset, string $variantName, string $format = 'jpg'): ?string
  {
    foreach ($asset->getVariants() as $v) {
      if ($v->getVariant() === $variantName && $v->getFormat() === $format) {
        return $this->storage->publicUrl($v->getS3Key());
      }
    }
    return null;
  }

  public function getOriginalUrl(MediaAsset $asset, string $format = 'jpg'): ?string
  {
    $key = $format === 'webp' ? $asset->getOriginalKeyWebp() : $asset->getOriginalKeyJpg();
    return $key ? $this->storage->publicUrl($key) : null;
  }
}
```

Použití v šabloně:

```latte
{* Získání assetů pro produkt *}
{var $mainImage = $product->getMediaLinks()
  ->filter(fn($l) => $l->getRole() === 'main')
  ->first()
  ?->getAsset()}

{if $mainImage}
  <picture>
    <source srcset="{$mediaUrlResolver->getVariantUrl($mainImage, 'large', 'webp')}" type="image/webp">
    <img src="{$mediaUrlResolver->getVariantUrl($mainImage, 'large', 'jpg')}" alt="...">
  </picture>
{/if}
```

## Worker deployment

### Docker Compose

```yaml
services:
  web:
    image: your-app
    # ...

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

```ini
[Unit]
Description=MediaS3 Worker
After=network.target rabbitmq.service mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php packages/media-s3/bin/worker.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Scaling

Můžete spustit více workerů paralelně:

```yaml
worker:
  image: your-app
  command: php packages/media-s3/bin/worker.php
  restart: unless-stopped
  deploy:
    replicas: 3  # 3 paralelní workers
```

Worker používá:
- `basic_qos(prefetch=10)` pro fair dispatch
- Optimistic locking v DB (claim pattern) pro idempotenci
- Každý worker zpracovává max 10 zpráv současně

## Pokročilá konfigurace

### Vlastní entity namespace

Pokud potřebujete použít vlastní entity (např. dědění):

```neon
mediaS3:
  entities:
    mediaAsset: 'App\Model\Entity\CustomMediaAsset'
    mediaOwnerLink: 'App\Model\Entity\CustomMediaOwnerLink'
    mediaVariant: 'App\Model\Entity\CustomMediaVariant'
```

Vlastní entity musí dědit nebo replikovat strukturu originálních.

### Multiple S3 buckety

Pro různé profily můžete teoreticky použít různé buckety, ale knihovna aktuálně podporuje pouze jeden bucket. Řešení:

1. Použít prefix v profilech (`products/`, `blog/`, atd.)
2. Nebo vytvořit multiple `MediaManager` instance s různými `S3Storage` (custom DI config)

### CDN integrace

```neon
mediaS3:
  s3:
    publicBaseUrl: 'https://cdn.vase-domena.cz'
```

Nastavte Cloudflare/jiný CDN aby proxoval:
- `cdn.vase-domena.cz/*` → `{bucket}.{endpoint}/*`
- Cache: vše (max-age je nastaven na 1 rok)

### Custom logger

```php
use Psr\Log\LoggerInterface;

// V DI extension
$builder->getDefinition($this->prefix('mediaManager'))
  ->setArguments([
    // ... ostatní
    $this->prefix('@logger'),  // PSR-3 logger
  ]);
```

## Časté problémy a řešení

### 1. Memory limit při zpracování velkých obrázků

**Problém:** `ImageProcessingException: Insufficient memory`

**Řešení:**
- Zvýšit `memory_limit` v php.ini (doporučeno min 256M, ideálně 512M)
- Snížit `maxOriginalLongEdge` v profilu
- Validovat velikost obrázků před uploadem

### 2. WEBP/AVIF nepodporováno

**Problém:** GD neobsahuje podporu WEBP/AVIF

**Řešení:**
- Nainstalovat GD s `--with-webp` a `--with-avif`
- Na Alpine/Debian: `apk add libwebp-dev libavif-dev` + rebuild GD
- Nebo odebrat z `formats` v konfiguraci

### 3. Worker nespustí zpracování

**Problém:** Assety zůstávají ve stavu QUEUED

**Kontrola:**
1. Je worker spuštěný? `docker ps | grep worker`
2. RabbitMQ connection OK? Zkontrolovat logy workera
3. Je zpráva ve frontě? RabbitMQ management UI
4. Není chyba v DB connection? Zkontrolovat logy workera

### 4. S3 upload selhává

**Problém:** `S3StorageException: S3 upload failed`

**Kontrola:**
1. Credentials správné? (`accessKey`, `secretKey`)
2. Bucket existuje?
3. Endpoint správný? (např. `https://eu.contabo.com` pro Contabo EU)
4. Region odpovídá? (např. `eu2` pro Contabo)
5. Network dostupnost? Firewall/security groups

### 5. Remote download timeout

**Problém:** `ValidationException: HTTP download failed`

**Řešení:**
- Zvýšit `http.timeoutSeconds` v konfiguraci
- Zkontrolovat dostupnost remote URL
- Pokud je soubor velký, zvýšit `http.maxBytes`

## Bezpečnostní doporučení

1. **Validace uploadů:**
   - Knihovna validuje MIME type pomocí `getimagesizefromstring()`
   - Whitelist povolených typů: JPEG, PNG, GIF, WEBP, AVIF
   - Max velikost: 50MB (hardcoded v `MediaManager::MAX_FILE_SIZE`)

2. **Remote URL:**
   - Blokování localhost a private IP (SSRF protection)
   - Pouze HTTP/HTTPS protokoly
   - Timeout pro prevenci slow loris útoků

3. **S3 ACL:**
   - Výchozí: `public-read` (objekty jsou veřejně dostupné)
   - Pokud potřebujete privátní: upravit `S3Storage::put()` a použít signed URLs

4. **Credentials:**
   - Neukládat v kódu, použít ENV variables
   - V Nette: `%env.AWS_ACCESS_KEY_ID%` v config.neon

5. **Rate limiting:**
   - Implementovat rate limiting na uploadech (mimo tuto knihovnu)
   - Limit počtu uploadů per user/IP

## Performance tipy

1. **Batch upload:**
   - Knihovna automaticky uploaduje všechny soubory paralelně (concurrency=5)
   - Výrazně rychlejší než sekvenční upload

2. **Worker scaling:**
   - Více workerů = rychlejší zpracování remote obrázků
   - Optimální: 2-5 workers pro normální load
   - Prefetch=10 zajišťuje fair dispatch

3. **CDN caching:**
   - Cache-Control: 1 rok
   - Immutable URL (včetně asset ID v cestě)
   - Cloudflare/jiný CDN maximálně cachuje

4. **Deduplikace:**
   - Ušetří zpracování a storage pro duplicitní obrázky
   - Obzvláště užitečné při importech

5. **Database indexy:**
   - Indexy na `owner_type`, `owner_id` v `media_owner_link`
   - Index na `checksum_sha1` pro rychlou deduplikaci
   - Index na `status` pro efektivní worker claim

## Testování

### Unit testy

Pro testování bez reálného S3/RabbitMQ:

```php
// Mock S3Storage
$s3Mock = Mockery::mock(S3Storage::class);
$s3Mock->shouldReceive('putMultiple')->once();

// Mock RabbitPublisher
$rabbitMock = Mockery::mock(RabbitPublisher::class);
$rabbitMock->shouldReceive('publishProcessAsset')->never(); // pro sync upload

$manager = new MediaManager($profiles, $s3Mock, $images, $downloader, $rabbitMock);
```

### Integration testy

```php
// Použití MinIO jako local S3
// docker run -p 9000:9000 minio/minio server /data

$cfg = [
  'endpoint' => 'http://localhost:9000',
  'region' => 'us-east-1',
  'bucket' => 'test-bucket',
  'accessKey' => 'minioadmin',
  'secretKey' => 'minioadmin',
];
$s3 = new S3Storage($cfg);

// Test upload
$s3->put('test.jpg', $jpegBytes, 'image/jpeg');
```

## Migrace z jiných systémů

### Z lokálního filesystému

```php
// Pro každý starý obrázek
$oldPath = '/var/www/uploads/products/123/image.jpg';
$bytes = file_get_contents($oldPath);

// Vytvoření temporary FileUpload
$tempFile = sys_get_temp_dir() . '/' . uniqid('migrate_') . '.jpg';
file_put_contents($tempFile, $bytes);

$upload = new class($tempFile) extends Nette\Http\FileUpload {
  public function __construct(string $path) {
    $this->name = basename($path);
    $this->tmpName = $path;
    $this->size = filesize($path);
  }
  public function isOk(): bool { return true; }
  public function isImage(): bool { return true; }
  public function getTemporaryFile(): string { return $this->tmpName; }
};

$asset = $mediaManager->uploadLocalWithDedup($em, $upload, 'product', 'Product', 123, 'main', 0);
unlink($tempFile);
```

### Z jiného S3/CDN

```php
// Pro každou remote URL
$asset = $mediaManager->uploadRemoteWithDedup(
  $em,
  'https://old-cdn.com/products/123/image.jpg',
  'product',
  'Product',
  123,
  'main',
  0
);
```

Výhodou `uploadRemoteWithDedup` je automatická deduplikace při migraci.

## Changelog a versioning

Knihovna používá semantic versioning. Aktuální verze sledujte v `composer.json`.

**Breaking changes:**
- Změna DB schématu (vyžaduje migrace)
- Změna konfigurace formátu
- Odebrání deprecated metod

**Minor changes:**
- Nové metody/features
- Performance vylepšení
- Bug fixes

## Podpora a issues

Pro hlášení bugů nebo feature requests použijte GitHub issues na projektu.

## Licence

MIT License - viz composer.json
