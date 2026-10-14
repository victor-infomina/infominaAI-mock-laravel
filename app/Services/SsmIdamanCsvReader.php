<?php

namespace App\Services;

/**
 * Reads a CSV export of Idaman documents into the row shape
 * SsmFixtureBuilder::assembleIdamanDocuments expects, grouped by reg_no.
 *
 * Required columns (any order, header row required):
 *   reg_no, version_id, form, document_date, s3_url
 *
 * s3_url is the S3 object key of the stored get-image envelope. A full
 * https://{bucket}.s3.{region}.amazonaws.com/{key} URL is reduced to its key —
 * the bucket itself always comes from SSM_S3_BUCKET, like the Sync Cases tool.
 */
class SsmIdamanCsvReader
{
    private const REQUIRED_COLUMNS = ['reg_no', 'version_id', 'form', 'document_date', 's3_url'];

    /** @return array<string, list<object>> reg_no => rows */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new \InvalidArgumentException("Cannot open Idaman CSV file {$path}");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');

            if ($header === false || $header === [null]) {
                throw new \InvalidArgumentException('Idaman CSV file is empty');
            }

            $columns = $this->normaliseHeader($header);
            $byRegNo = [];
            $lineNo = 1;

            while (($fields = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $lineNo++;

                if ($fields === [null] || implode('', $fields) === '') {
                    continue;
                }

                $get = function (string $column) use ($fields, $columns, $lineNo): string {
                    $value = trim((string) ($fields[$columns[$column]] ?? ''));

                    if ($value === '') {
                        throw new \InvalidArgumentException("Idaman CSV row {$lineNo}: {$column} is required");
                    }

                    return $value;
                };

                $regNo = $get('reg_no');

                $byRegNo[$regNo][] = (object) [
                    'version_id' => $get('version_id'),
                    'form' => $get('form'),
                    'document_date' => $get('document_date'),
                    's3_url' => $this->objectKey($get('s3_url')),
                ];
            }

            return $byRegNo;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, int> column name => index */
    private function normaliseHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $name) {
            $name = (string) $name;

            if ($index === 0 && str_starts_with($name, "\xEF\xBB\xBF")) {
                $name = substr($name, 3);
            }

            $columns[strtolower(trim($name))] = $index;
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));

        if ($missing !== []) {
            throw new \InvalidArgumentException('Idaman CSV is missing required column(s): '.implode(', ', $missing));
        }

        return $columns;
    }

    private function objectKey(string $value): string
    {
        if (preg_match('#^https?://[^/]+/(.+)$#i', $value, $matches)) {
            return rawurldecode($matches[1]);
        }

        return ltrim($value, '/');
    }
}
