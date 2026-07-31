<?php

namespace Tests\Fakes;

use App\Services\S3ObjectFetcher;

class FakeS3ObjectFetcher implements S3ObjectFetcher
{
    /** @param array<string, string> $objects keyed by "{bucket}/{key}" */
    public function __construct(private readonly array $objects)
    {
    }

    public function fetch(string $bucket, string $key): string
    {
        return $this->objects["{$bucket}/{$key}"] ?? throw new \RuntimeException("no fixture object for {$bucket}/{$key}");
    }
}
