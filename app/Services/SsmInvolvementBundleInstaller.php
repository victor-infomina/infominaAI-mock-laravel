<?php

namespace App\Services;

use App\Services\SsmGateway\InvolvementVariant;
use Illuminate\Support\Facades\File;

/**
 * Validates one IC's involvement bundle (meta.json plus `{identity}.pdf` files) and publishes it under
 * the involvements root, replacing that IC's previous folder and optionally the default marker.
 *
 * Validation failures throw \InvalidArgumentException; filesystem failures throw \RuntimeException after
 * restoring the previous folder and marker. This rolls back operational failures; it is not a
 * crash-consistent multi-file transaction.
 */
class SsmInvolvementBundleInstaller
{
    public function validate(string $directory, string $idValue): void
    {
        if (! SsmGatewayInvolvementRepository::isSafeId($idValue)) {
            throw new \InvalidArgumentException("IC \"{$idValue}\" may only contain letters, digits, - and _");
        }

        if (is_link($directory) || ! is_dir($directory)) {
            throw new \InvalidArgumentException("Bundle for {$idValue} is not a directory");
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new \RuntimeException("Could not read bundle directory for {$idValue}");
        }

        $pdfs = 0;

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;

            if (is_link($path) || ! is_file($path)) {
                throw new \InvalidArgumentException("Bundle entry {$entry} for {$idValue} is not a regular file");
            }

            if ($entry === 'meta.json') {
                $this->validateMeta($path, $idValue);

                continue;
            }

            if (! str_ends_with($entry, '.pdf') || ! InvolvementVariant::isValid(substr($entry, 0, -4))) {
                throw new \InvalidArgumentException("Bundle entry {$entry} for {$idValue} is not a recognised involvement sample");
            }

            $handle = fopen($path, 'rb');
            $signature = $handle === false ? false : fread($handle, 5);

            if ($handle !== false) {
                fclose($handle);
            }

            if ($signature !== '%PDF-') {
                throw new \InvalidArgumentException("Bundle entry {$entry} for {$idValue} is not a PDF");
            }

            $pdfs++;
        }

        if (! in_array('meta.json', $entries, true)) {
            throw new \InvalidArgumentException("Bundle for {$idValue} is missing meta.json");
        }

        if ($pdfs === 0) {
            throw new \InvalidArgumentException("Bundle for {$idValue} has no PDF samples");
        }
    }

    public function install(string $directory, string $root, string $idValue, bool $setDefault): void
    {
        $this->validate($directory, $idValue);
        $root = rtrim($root, '/');

        if (! is_dir($root) && ! @mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new \RuntimeException("Could not create involvements root {$root}");
        }

        $suffix = bin2hex(random_bytes(6));
        $staging = "{$root}/.staging-{$idValue}-{$suffix}";
        $backup = "{$root}/.backup-{$idValue}-{$suffix}";
        $markerTmp = "{$root}/.default-{$suffix}.json";
        $target = "{$root}/{$idValue}";
        $marker = $root.'/'.SsmGatewayInvolvementRepository::DEFAULT_MARKER;

        try {
            $this->copyBundle($directory, $staging);
            $this->validate($staging, $idValue);

            $lock = fopen("{$root}/.import.lock", 'c');

            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Could not lock involvements root {$root}");
            }

            try {
                $this->publish($staging, $target, $backup, $marker, $markerTmp, $idValue, $setDefault);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            if (is_dir($staging)) {
                File::deleteDirectory($staging);
            }

            if (is_file($markerTmp)) {
                @unlink($markerTmp);
            }
        }
    }

    private function publish(
        string $staging, string $target, string $backup, string $marker, string $markerTmp, string $idValue, bool $setDefault,
    ): void {
        $hadTarget = file_exists($target) || is_link($target);

        if ($hadTarget && ! rename($target, $backup)) {
            throw new \RuntimeException("Could not move aside the existing bundle for {$idValue}");
        }

        $placed = false;

        try {
            if (! rename($staging, $target)) {
                throw new \RuntimeException("Could not publish the bundle for {$idValue}");
            }

            $placed = true;

            if ($setDefault) {
                if (is_link($marker)) {
                    throw new \RuntimeException('Default marker '.$marker.' is a symlink');
                }

                $json = json_encode(['idValue' => $idValue], JSON_THROW_ON_ERROR)."\n";

                if (file_put_contents($markerTmp, $json) !== strlen($json) || ! @rename($markerTmp, $marker)) {
                    throw new \RuntimeException("Could not set {$idValue} as the default IC");
                }
            }
        } catch (\Throwable $e) {
            if ($placed) {
                File::deleteDirectory($target);
            }

            if ($hadTarget && ! rename($backup, $target)) {
                throw new \RuntimeException("{$e->getMessage()}; the previous bundle could not be restored from {$backup}", 0, $e);
            }

            throw $e;
        }

        if ($hadTarget) {
            is_link($backup) ? unlink($backup) : File::deleteDirectory($backup);
        }
    }

    private function validateMeta(string $path, string $idValue): void
    {
        $meta = json_decode((string) file_get_contents($path), true);

        if (! is_array($meta)) {
            throw new \InvalidArgumentException("meta.json for {$idValue} is not a JSON object");
        }

        foreach (['idType', 'idValue', 'name'] as $field) {
            if (! is_string($meta[$field] ?? null) || $meta[$field] === '') {
                throw new \InvalidArgumentException("meta.json for {$idValue} needs a non-empty string {$field}");
            }
        }

        if ($meta['idValue'] !== $idValue) {
            throw new \InvalidArgumentException("meta.json idValue {$meta['idValue']} does not match {$idValue}");
        }
    }

    private function copyBundle(string $source, string $destination): void
    {
        if (! mkdir($destination, 0755)) {
            throw new \RuntimeException("Could not create staging directory {$destination}");
        }

        foreach (array_diff(scandir($source), ['.', '..']) as $entry) {
            if (! copy($source.'/'.$entry, $destination.'/'.$entry)) {
                throw new \RuntimeException("Could not stage {$entry}");
            }
        }
    }
}
