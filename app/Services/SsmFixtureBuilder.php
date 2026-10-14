<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SsmFixtureBuilder
{
    public function __construct(private readonly S3ObjectFetcher $fetcher) {}

    public function findRequestRow(string $requestId): ?object
    {
        $row = DB::connection('be_pgsql')
            ->table('datasource_requests as dr')
            ->leftJoin('datasource_transactions as dt', 'dt.request_id_id', '=', 'dr.id')
            ->leftJoin('datasource_purchased as dp', 'dp.transaction_id_id', '=', 'dt.id')
            ->leftJoin('datasource_reports as drep', 'drep.id', '=', 'dp.report_id')
            ->where('dr.id', $requestId)
            ->select([
                'dr.id',
                'dr.subject_name',
                'dr.subject_reg_no',
                'dr.type',
                'dp.raw_s3_url',
                'drep.s3_url as pdf_s3_url',
            ])
            ->first();

        return $row ?: null;
    }

    public function assembleCase(string $casePath, object $row): void
    {
        if (! is_dir($casePath) && ! mkdir($casePath, 0755, true) && ! is_dir($casePath)) {
            throw new \RuntimeException("Could not create {$casePath}");
        }

        $entityType = strtolower($row->type ?: 'company');

        $this->downloadIfPresent($row->raw_s3_url, "{$casePath}/{$entityType}-profile.json");
        $this->downloadIfPresent($row->pdf_s3_url, "{$casePath}/report.pdf");

        file_put_contents("{$casePath}/meta.json", json_encode([
            'regNo' => $row->subject_reg_no,
            'companyName' => $row->subject_name,
            'entityType' => $entityType,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    public function assembleIdamanDocuments(string $casePath, array $idamanRows, string $bucket): void
    {
        if ($idamanRows === []) {
            return;
        }

        $idamanDir = "{$casePath}/idaman";

        if (! is_dir($idamanDir) && ! mkdir($idamanDir, 0755, true) && ! is_dir($idamanDir)) {
            throw new \RuntimeException("Could not create {$idamanDir}");
        }

        $meta = json_decode((string) @file_get_contents("{$casePath}/meta.json"), true);
        $companyNo = (string) ($meta['regNo'] ?? '');
        $list = [];

        foreach ($idamanRows as $row) {
            // Mirror the real gateway's documentInfos entry shape; the BE and FE
            // read documentDate (YYYYMMDD), formType and verId from it.
            $list[] = [
                'batchId' => (string) Str::uuid(),
                'comments' => '',
                'companyNo' => $companyNo,
                'dateFiler' => IdamanDocumentDates::iso((string) $row->document_date),
                'documentDate' => IdamanDocumentDates::yyyymmdd((string) $row->document_date),
                'formType' => (string) $row->form,
                'imageName' => "{$row->version_id}.TIF",
                'receivedDate' => IdamanDocumentDates::yyyymmdd((string) $row->document_date),
                'sourceData' => '',
                'stateCode' => '',
                'totalPage' => '1',
                'verId' => (string) $row->version_id,
            ];

            if (! $row->s3_url) {
                continue;
            }

            $envelope = json_decode($this->fetcher->fetch($bucket, $row->s3_url), true);
            $docContent = $envelope['getImage']['docContent'] ?? null;

            if (is_string($docContent) && $docContent !== '') {
                file_put_contents("{$idamanDir}/{$row->version_id}.tiff", base64_decode($docContent));
            }
        }

        file_put_contents("{$idamanDir}/list.json", json_encode($list, JSON_PRETTY_PRINT));
    }

    private function downloadIfPresent(?string $url, string $destPath): void
    {
        if (! $url) {
            return;
        }

        if (! preg_match('#^https://(.+?)\.s3\.(.+?)\.amazonaws\.com/(.+)$#', $url, $matches)) {
            return;
        }

        [, $bucket, , $key] = $matches;

        file_put_contents($destPath, $this->fetcher->fetch($bucket, rawurldecode($key)));
    }
}
