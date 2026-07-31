<?php

namespace App\Http\Controllers;

use App\Services\DevDbEntityRepository;
use App\Services\SsmFixtureBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SyncCasesController extends Controller
{
    public function __construct(
        private readonly DevDbEntityRepository $repository,
        private readonly SsmFixtureBuilder $fixtureBuilder,
    ) {
    }

    public function index(Request $request): View
    {
        $query = (string) $request->query('q', '');
        $type = $request->query('type') ?: null;
        $page = max(1, (int) $request->query('page', 1));

        $result = $query !== ''
            ? $this->repository->search($query, $type, $page)
            : ['total' => 0, 'items' => []];

        return view('sync-cases.index', [
            'query' => $query,
            'type' => $type,
            'page' => $page,
            'total' => $result['total'],
            'items' => $result['items'],
        ]);
    }

    public function show(string $requestId): View
    {
        $row = $this->fixtureBuilder->findRequestRow($requestId);

        abort_if($row === null, 404);

        $idamanDocuments = $this->repository->findIdamanDocuments($row->subject_reg_no);

        return view('sync-cases.show', [
            'requestId' => $requestId,
            'row' => $row,
            'idamanDocuments' => $idamanDocuments,
            'syncResult' => session('syncResult'),
        ]);
    }

    public function sync(Request $request, string $requestId): RedirectResponse
    {
        $row = $this->fixtureBuilder->findRequestRow($requestId);

        abort_if($row === null, 404);

        $selectedVersionIds = (array) $request->input('idaman_version_ids', []);
        $idamanDocuments = array_values(array_filter(
            $this->repository->findIdamanDocuments($row->subject_reg_no),
            fn ($doc) => in_array($doc->version_id, $selectedVersionIds, true),
        ));

        $caseKey = Str::slug($row->subject_name ?: $row->subject_reg_no ?: $row->id);
        $tempCasePath = sys_get_temp_dir().'/ssm-sync-'.uniqid().'/'.$caseKey;

        $this->fixtureBuilder->assembleCase($tempCasePath, $row);
        $this->fixtureBuilder->assembleIdamanDocuments($tempCasePath, $idamanDocuments, config('ssm_mock.s3_bucket'));

        $zipPath = $this->zipDirectory($tempCasePath, $caseKey);

        $response = Http::withHeaders([
            'x-Gateway-APIKey' => config('ssm_mock.admin_sync_key'),
            'x-Gateway-APISecret' => config('ssm_mock.admin_sync_secret'),
        ])->attach('bundle', file_get_contents($zipPath), 'case.zip')
            ->post(rtrim(config('ssm_mock.remote_url'), '/').'/admin-api/cases');

        $result = $response->successful()
            ? 'synced: '.$response->json('caseKey')
            : 'failed: '.$response->status().' '.$response->body();

        return redirect("/admin/sync-cases/{$requestId}")->with('syncResult', $result);
    }

    private function zipDirectory(string $directory, string $topDirName): string
    {
        $zipPath = sys_get_temp_dir().'/'.uniqid('sync-upload-', true).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $relative = $topDirName.'/'.substr($file->getPathname(), strlen($directory) + 1);
            $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();

        return $zipPath;
    }
}
