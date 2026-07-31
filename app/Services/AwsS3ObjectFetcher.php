<?php

namespace App\Services;

use Aws\S3\S3Client;

class AwsS3ObjectFetcher implements S3ObjectFetcher
{
    public function __construct(private readonly S3Client $client)
    {
    }

    public function fetch(string $bucket, string $key): string
    {
        return (string) $this->client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ])['Body'];
    }
}
