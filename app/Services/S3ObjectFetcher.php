<?php

namespace App\Services;

interface S3ObjectFetcher
{
    public function fetch(string $bucket, string $key): string;
}
