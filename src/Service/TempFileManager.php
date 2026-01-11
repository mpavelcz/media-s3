<?php declare(strict_types=1);

namespace MediaS3\Service;

use Nette\Http\FileUpload;
use Nette\Utils\FileSystem;
use Nette\Utils\Random;
use RuntimeException;

final class TempFileManager
{
    public function __construct(
        private string $tempDir
    ) {
        if (empty($this->tempDir)) {
            throw new RuntimeException('Temp directory path must be configured');
        }
    }

    /**
     * Uloží nahraný soubor do dočasného adresáře s datem-based strukturou
     *
     * @param FileUpload $upload Nahraný soubor
     * @return string Plná cesta k uloženému souboru
     * @throws RuntimeException Pokud se nepodaří vytvořit adresář nebo uložit soubor
     */
    public function saveTempFile(FileUpload $upload): string
    {
        // Vytvoříme strukturu: temp/uploads/YYYY/MM/DD/
        $dateDir = date('Y/m/d');
        $targetDir = $this->tempDir . '/' . $dateDir;

        // Vytvoříme adresář pokud neexistuje
        if (!is_dir($targetDir)) {
            try {
                FileSystem::createDir($targetDir);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    sprintf('Failed to create temp directory %s: %s', $targetDir, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        // Vygenerujeme unikátní název: {timestamp}_{random}_{originalName}
        $timestamp = time();
        $random = Random::generate(8, '0-9a-z');
        $originalName = $upload->getSanitizedName();
        $fileName = sprintf('%d_%s_%s', $timestamp, $random, $originalName);

        $targetPath = $targetDir . '/' . $fileName;

        // Přesuneme upload do temp adresáře
        try {
            $upload->move($targetPath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('Failed to move uploaded file to %s: %s', $targetPath, $e->getMessage()),
                0,
                $e
            );
        }

        return $targetPath;
    }

    /**
     * Uloží raw bytes do dočasného souboru
     *
     * @param string $bytes Obsah souboru
     * @param string $extension Přípona souboru (např. 'jpg')
     * @return string Plná cesta k uloženému souboru
     * @throws RuntimeException Pokud se nepodaří vytvořit adresář nebo uložit soubor
     */
    public function saveTempBytes(string $bytes, string $extension = 'tmp'): string
    {
        $dateDir = date('Y/m/d');
        $targetDir = $this->tempDir . '/' . $dateDir;

        if (!is_dir($targetDir)) {
            try {
                FileSystem::createDir($targetDir);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    sprintf('Failed to create temp directory %s: %s', $targetDir, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        $timestamp = time();
        $random = Random::generate(8, '0-9a-z');
        $fileName = sprintf('%d_%s.%s', $timestamp, $random, $extension);
        $targetPath = $targetDir . '/' . $fileName;

        $result = file_put_contents($targetPath, $bytes);
        if ($result === false) {
            throw new RuntimeException(
                sprintf('Failed to write temp file %s', $targetPath)
            );
        }

        return $targetPath;
    }

    /**
     * Smaže dočasný soubor
     *
     * @param string $path Cesta k souboru
     * @return void
     */
    public function deleteTempFile(string $path): void
    {
        if (file_exists($path)) {
            try {
                FileSystem::delete($path);
            } catch (\Throwable $e) {
                // Logujeme ale nepropagujeme chybu - není kritická
                error_log(sprintf('Failed to delete temp file %s: %s', $path, $e->getMessage()));
            }
        }
    }

    /**
     * Smaže všechny dočasné soubory starší než zadaný počet hodin
     *
     * @param int $olderThanHours Smazat soubory starší než tento počet hodin
     * @return int Počet smazaných souborů
     */
    public function cleanupOldFiles(int $olderThanHours = 24): int
    {
        if (!is_dir($this->tempDir)) {
            return 0;
        }

        $deleted = 0;
        $cutoffTime = time() - ($olderThanHours * 3600);

        // Rekurzivně projdeme všechny soubory v temp adresáři
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoffTime) {
                try {
                    FileSystem::delete($file->getPathname());
                    $deleted++;
                } catch (\Throwable $e) {
                    error_log(sprintf('Failed to delete old temp file %s: %s', $file->getPathname(), $e->getMessage()));
                }
            }
        }

        // Smažeme prázdné adresáře
        foreach ($iterator as $dir) {
            if ($dir->isDir()) {
                try {
                    @rmdir($dir->getPathname());
                } catch (\Throwable $e) {
                    // Ignore - není kritické
                }
            }
        }

        return $deleted;
    }
}
