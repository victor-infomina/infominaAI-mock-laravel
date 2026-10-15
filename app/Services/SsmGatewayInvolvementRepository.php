<?php

namespace App\Services;

class SsmGatewayInvolvementRepository
{
    private const REF_PREFIX = 'PI-';

    public function __construct(private readonly string $basePath) {}

    public function find(string $idValue): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $idValue)) {
            return null;
        }

        $path = $this->basePath.'/'.$idValue;
        $metaPath = $path.'/meta.json';

        if (! is_file($metaPath)) {
            return null;
        }

        $meta = json_decode(file_get_contents($metaPath), true);

        if (! is_array($meta)) {
            return null;
        }

        return ['key' => $idValue, 'meta' => $meta, 'path' => $path];
    }

    public function reportFile(array $involvement): ?string
    {
        $file = $involvement['path'].'/report.pdf';

        return is_file($file) ? $file : null;
    }

    public function reportUrl(array $involvement): string
    {
        return route('ssm-gateway.involvement-report', ['idValue' => $involvement['key']]);
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
