<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DevDbEntityRepository
{
    public function search(string $query, ?string $type, int $page = 1, int $perPage = 20): array
    {
        $base = DB::connection('be_pgsql')
            ->table('datasource_requests as dr')
            ->leftJoin('datasource_transactions as dt', 'dt.request_id_id', '=', 'dr.id')
            ->leftJoin('datasource_purchased as dp', 'dp.transaction_id_id', '=', 'dt.id')
            ->leftJoin('datasource_reports as drep', 'drep.id', '=', 'dp.report_id')
            ->whereNull('dr.deleted_at')
            ->whereNotNull('dp.raw_s3_url')
            ->where(function ($clause) use ($query) {
                // LOWER()+LIKE instead of Postgres's ILIKE so this also works
                // against the sqlite connection substituted in tests.
                $needle = '%'.strtolower($query).'%';
                $clause->whereRaw('LOWER(dr.subject_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(dr.subject_reg_no) LIKE ?', [$needle]);
            });

        if ($type !== null) {
            $base->where('dr.type', $type);
        }

        $total = (clone $base)->count('dr.id');

        $items = $base
            ->select([
                'dr.id',
                'dr.subject_name',
                'dr.subject_reg_no',
                'dr.type',
                'dp.raw_s3_url',
                'drep.s3_url as pdf_s3_url',
            ])
            ->orderByDesc('dr.created_at')
            ->forPage($page, $perPage)
            ->get()
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    public function findIdamanDocuments(string $regNo): array
    {
        return DB::connection('be_pgsql')
            ->table('idaman_document')
            ->where('entity_number', $regNo)
            ->where('status', 'READY')
            ->whereNotNull('s3_url')
            ->whereNull('deleted_at')
            ->select(['id', 'version_id', 'form', 'document_date', 's3_url'])
            ->orderByDesc('document_date')
            ->get()
            ->all();
    }
}
