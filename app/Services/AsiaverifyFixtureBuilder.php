<?php

namespace App\Services;

class AsiaverifyFixtureBuilder
{
    private const COUNTRY_BY_ITEM = [
        'vietnam_company_profile' => 'VNM',
        'thailand_company_profile' => 'THA',
        'china_company_profile' => 'CHN',
    ];

    public function __construct(private readonly S3ObjectFetcher $fetcher)
    {
    }

    public function assembleCase(string $casePath, object $row): void
    {
        if (! is_dir($casePath) && ! mkdir($casePath, 0755, true) && ! is_dir($casePath)) {
            throw new \RuntimeException("Could not create {$casePath}");
        }

        $country = self::COUNTRY_BY_ITEM[$row->item] ?? null;

        if ($country === null) {
            throw new \InvalidArgumentException("Unsupported AsiaVerify datasource item: {$row->item}");
        }

        file_put_contents("{$casePath}/meta.json", json_encode([
            'country' => $country,
            'companyId' => $row->subject_reg_no,
            'companyName' => $row->subject_name,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $envelope = json_decode($this->fetchRaw($row->raw_s3_url), true);

        file_put_contents("{$casePath}/profile.json", json_encode(
            $envelope['result'] ?? null,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ).PHP_EOL);
    }

    private function fetchRaw(string $url): string
    {
        if (! preg_match('#^https://(.+?)\.s3\.(.+?)\.amazonaws\.com/(.+)$#', $url, $matches)) {
            throw new \RuntimeException("Could not parse S3 URL: {$url}");
        }

        [, $bucket, , $key] = $matches;

        return $this->fetcher->fetch($bucket, rawurldecode($key));
    }
}
