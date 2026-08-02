<?php

namespace App\Services;

class DnbFixtureBuilder
{
    private const COUNTRY_BY_ITEM = [
        'singapore_company_profile' => 'singapore',
        'indonesia_company_profile' => 'indonesia',
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
            throw new \InvalidArgumentException("Unsupported DNB datasource item: {$row->item}");
        }

        $meta = $country === 'singapore'
            ? ['country' => $country, 'regNo' => $row->subject_reg_no, 'companyName' => $row->subject_name]
            : ['country' => $country, 'companyId' => $row->subject_reg_no, 'companyName' => $row->subject_name];

        file_put_contents("{$casePath}/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        file_put_contents("{$casePath}/profile.xml", $this->fetchRaw($row->raw_s3_url));
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
