<?php

namespace App\Services;

use App\Services\SsmGateway\InvolvementVariant;

/**
 * Preflights a production CSV export of personal-involvement purchases and groups it by IC,
 * before anything is downloaded.
 *
 * Required columns (any order, header row required):
 *   identification_type, identification_no, subject_name, variant_id, pdf_s3_key
 *
 * variant_id is a **production** id. Rows for ids outside the 12 Director / Shareholder / Secretary
 * involvement variants are warned about and skipped before their other fields are checked.
 * Identification numbers stay strings, so leading zeroes survive.
 */
class SsmInvolvementCsvReader
{
    private const REQUIRED_COLUMNS = ['identification_type', 'identification_no', 'subject_name', 'variant_id', 'pdf_s3_key'];

    /**
     * @return array{groups: list<array{idValue: string, meta: array{idType: string, idValue: string, name: string},
     *     rows: list<array{line: int, identity: string, pdf_s3_key: string}>}>, warnings: list<string>}
     */
    public function read(string $path, string $defaultId): array
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
            $groups = [];
            $seen = [];
            $warnings = [];
            $lineNo = 1;

            while (($fields = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $lineNo++;

                if ($fields === [null] || implode('', $fields) === '') {
                    continue;
                }

                if (count($fields) !== count($header)) {
                    throw new \InvalidArgumentException(
                        "CSV row {$lineNo}: expected ".count($header).' fields, got '.count($fields),
                    );
                }

                $get = fn (string $column) => trim((string) $fields[$columns[$column]]);
                $variantText = $get('variant_id');

                if (! ctype_digit($variantText)) {
                    throw new \InvalidArgumentException("CSV row {$lineNo}: variant_id \"{$variantText}\" must be a whole number");
                }

                $identity = InvolvementVariant::fromProduction((int) $variantText);

                if ($identity === null) {
                    $warnings[] = "CSV row {$lineNo}: unsupported production variant {$variantText}; skipped";

                    continue;
                }

                foreach (self::REQUIRED_COLUMNS as $column) {
                    if ($get($column) === '') {
                        throw new \InvalidArgumentException("CSV row {$lineNo}: {$column} is required");
                    }
                }

                $idValue = $get('identification_no');

                if (! SsmGatewayInvolvementRepository::isSafeId($idValue)) {
                    throw new \InvalidArgumentException(
                        "CSV row {$lineNo}: identification_no \"{$idValue}\" may only contain letters, digits, - and _",
                    );
                }

                $meta = ['idType' => $get('identification_type'), 'idValue' => $idValue, 'name' => $get('subject_name')];
                $groupKey = 'ic:'.$idValue;
                $pairKey = $groupKey.':'.$identity;

                if (isset($seen[$pairKey])) {
                    throw new \InvalidArgumentException("CSV row {$lineNo}: duplicate IC/variant pair {$idValue} {$identity}");
                }

                $seen[$pairKey] = true;

                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = ['idValue' => $idValue, 'meta' => $meta, 'rows' => []];
                } elseif ($groups[$groupKey]['meta'] !== $meta) {
                    throw new \InvalidArgumentException("CSV row {$lineNo}: conflicting metadata for {$idValue}");
                }

                $groups[$groupKey]['rows'][] = ['line' => $lineNo, 'identity' => $identity, 'pdf_s3_key' => $get('pdf_s3_key')];
            }
        } finally {
            fclose($handle);
        }

        if (! SsmGatewayInvolvementRepository::isSafeId($defaultId) || ! isset($groups['ic:'.$defaultId])) {
            throw new \InvalidArgumentException("Default IC {$defaultId} has no supported row in the CSV");
        }

        return ['groups' => array_values($groups), 'warnings' => $warnings];
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

            if (isset($columns[$name])) {
                throw new \InvalidArgumentException("CSV header has duplicate column {$name}");
            }

            $columns[$name] = $index;
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));

        if ($missing !== []) {
            throw new \InvalidArgumentException('CSV is missing required column(s): '.implode(', ', $missing));
        }

        return $columns;
    }
}
