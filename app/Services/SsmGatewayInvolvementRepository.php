<?php

namespace App\Services;

use App\Services\SsmGateway\InvolvementVariant;

class SsmGatewayInvolvementRepository
{
    private const REF_PREFIX = 'PI-';

    public const DEFAULT_MARKER = 'default.json';

    public function __construct(private readonly string $basePath) {}

    /**
     * Exact lookup of one IC's own fixture folder; never falls back to the default.
     */
    public function find(string $idValue): ?array
    {
        if (! self::isSafeId($idValue)) {
            return null;
        }

        $path = $this->basePath.'/'.$idValue;
        $metaPath = $path.'/meta.json';

        if (is_link($path) || ! is_dir($path) || ! is_file($metaPath)) {
            return null;
        }

        $meta = json_decode(file_get_contents($metaPath), true);

        if (! is_array($meta) || ($meta['idValue'] ?? null) !== $idValue) {
            return null;
        }

        foreach (['idType', 'idValue', 'name'] as $field) {
            if (! is_string($meta[$field] ?? null) || $meta[$field] === '') {
                return null;
            }
        }

        return ['key' => $idValue, 'meta' => $meta, 'path' => $path];
    }

    /**
     * Fixture that serves a submitted IC: its own folder when one exists (even a broken one, which then yields
     * null), otherwise the default IC named by the marker file.
     */
    public function resolve(string $submittedId): ?array
    {
        if (! self::isSafeId($submittedId)) {
            return null;
        }

        $own = $this->basePath.'/'.$submittedId;

        if (file_exists($own) || is_link($own)) {
            return $this->find($submittedId);
        }

        $marker = $this->basePath.'/'.self::DEFAULT_MARKER;
        $default = is_file($marker) ? json_decode(file_get_contents($marker), true) : null;

        return is_array($default) && is_string($default['idValue'] ?? null)
            ? $this->find($default['idValue'])
            : null;
    }

    public function reportFile(array $involvement, string $identity): ?string
    {
        if (! InvolvementVariant::isValid($identity)) {
            return null;
        }

        $file = $involvement['path'].'/'.$identity.'.pdf';

        return is_file($file) && ! is_link($file) ? $file : null;
    }

    public function reportUrl(array $involvement, string $identity): string
    {
        return route('ssm-gateway.involvement-report', ['idValue' => $involvement['key'], 'identity' => $identity]);
    }

    public static function isSafeId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]+\z/', $id) === 1;
    }

    public static function selectionRefNo(string $idValue): string
    {
        return self::REF_PREFIX.$idValue;
    }

    public static function idValueFromRef(string $ref): ?string
    {
        return str_starts_with($ref, self::REF_PREFIX) ? substr($ref, strlen(self::REF_PREFIX)) : null;
    }
}
