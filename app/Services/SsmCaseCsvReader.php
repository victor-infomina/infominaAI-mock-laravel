<?php

namespace App\Services;

/**
 * Reads a CSV export of purchased SSM reports into the row shape
 * SsmFixtureBuilder::assembleCase expects.
 *
 * Required columns (any order, header row required):
 *   company_name, reg_no, entity_type, raw_s3_url, pdf_s3_url
 *
 * entity_type must be company, business or llp (case-insensitive).
 * The S3 URL columns accept either a full
 * https://{bucket}.s3.{region}.amazonaws.com/{key} URL or a bare object key,
 * which is expanded with the configured bucket and region. pdf_s3_url may be empty.
 */
class SsmCaseCsvReader
{
    private const REQUIRED_COLUMNS = ['company_name', 'reg_no', 'entity_type', 'raw_s3_url', 'pdf_s3_url'];

    private const ENTITY_TYPES = ['company', 'business', 'llp'];

    public function __construct(
        private readonly ?string $bucket,
        private readonly ?string $region,
    ) {
    }

    /** @return list<object> */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new \InvalidArgumentException("Cannot open CSV file {$path}");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');

            if ($header === false || $header === [null]) {
                throw new \InvalidArgumentException('CSV file is empty');
            }

            $columns = $this->normaliseHeader($header);
            $rows = [];
            $lineNo = 1;

            while (($fields = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $lineNo++;

                if ($fields === [null] || implode('', $fields) === '') {
                    continue;
                }

                $get = fn (string $column) => trim((string) ($fields[$columns[$column]] ?? ''));

                $rows[] = (object) [
                    'id' => (string) $lineNo,
                    'subject_name' => $get('company_name'),
                    'subject_reg_no' => $get('reg_no'),
                    'type' => $this->entityType($get('entity_type'), $lineNo),
                    'raw_s3_url' => $this->s3Url($get('raw_s3_url'), 'raw_s3_url', $lineNo, required: true),
                    'pdf_s3_url' => $this->s3Url($get('pdf_s3_url'), 'pdf_s3_url', $lineNo, required: false),
                ];
            }

            return $rows;
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

            $name = strtolower(trim($name));

            $columns[$name] = $index;
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));

        if ($missing !== []) {
            throw new \InvalidArgumentException('CSV is missing required column(s): '.implode(', ', $missing));
        }

        return $columns;
    }

    private function entityType(string $value, int $lineNo): string
    {
        $type = strtolower($value);

        if (! in_array($type, self::ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException(
                "CSV row {$lineNo}: entity_type \"{$value}\" must be one of ".implode(', ', self::ENTITY_TYPES),
            );
        }

        return $type;
    }

    private function s3Url(string $value, string $column, int $lineNo, bool $required): ?string
    {
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException("CSV row {$lineNo}: {$column} is required");
            }

            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        if (! $this->bucket || ! $this->region) {
            throw new \InvalidArgumentException(
                "CSV row {$lineNo}: {$column} is a bare S3 key but SSM_S3_BUCKET / SSM_S3_REGION are not configured",
            );
        }

        return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/".ltrim($value, '/');
    }
}
