<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CaseUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['bundle' => ['required', 'file']]);

        $uploadedPath = $request->file('bundle')->getRealPath();
        $zip = new \ZipArchive();

        if ($zip->open($uploadedPath) !== true) {
            return response()->json(['error' => 'invalid zip'], 422);
        }

        $topDir = null;
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (str_ends_with($name, '/')) {
                continue;
            }

            $segments = explode('/', $name);

            if (count($segments) < 2 || in_array('..', $segments, true) || $segments[0] === '') {
                $zip->close();

                return response()->json(['error' => 'unsafe zip entry: '.$name], 422);
            }

            $topDir ??= $segments[0];

            if ($segments[0] !== $topDir) {
                $zip->close();

                return response()->json(['error' => 'zip must contain exactly one top-level directory'], 422);
            }

            $entries[] = $name;
        }

        if ($topDir === null) {
            $zip->close();

            return response()->json(['error' => 'empty zip'], 422);
        }

        $metaEntry = "{$topDir}/meta.json";

        if (! in_array($metaEntry, $entries, true)) {
            $zip->close();

            return response()->json(['error' => 'bundle is missing meta.json'], 422);
        }

        $caseKey = Str::slug($topDir);
        $destination = rtrim(config('ssm_mock.cases_path'), '/')."/{$caseKey}";

        if (is_dir($destination)) {
            $this->deleteDirectory($destination);
        }

        mkdir($destination, 0755, true);

        foreach ($entries as $entry) {
            $relative = substr($entry, strlen($topDir) + 1);
            $targetPath = "{$destination}/{$relative}";
            $targetDir = dirname($targetPath);

            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            file_put_contents($targetPath, $zip->getFromName($entry));
        }

        $zip->close();

        return response()->json(['caseKey' => $caseKey, 'status' => 'synced']);
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = "{$path}/{$item}";

            is_dir($itemPath) ? $this->deleteDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }
}
