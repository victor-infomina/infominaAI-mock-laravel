<?php

namespace App\Http\Controllers;

use App\Services\SsmGateway\InvolvementVariant;
use App\Services\SsmGatewayInvolvementRepository;
use App\Services\SsmInvolvementBundleInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Receives one IC's involvement bundle from `ssm:import-involvements --push`: a zip holding exactly
 * {idValue}/meta.json and {idValue}/{identity}.pdf entries. Every entry is checked before anything is
 * written; the bundle is then unpacked to a private temp directory and published by the installer.
 */
class InvolvementUploadController extends Controller
{
    public function store(Request $request, SsmInvolvementBundleInstaller $installer): JsonResponse
    {
        $request->validate([
            'bundle' => ['required', 'file'],
            'set_default' => ['sometimes', 'boolean'],
        ]);

        $zip = new \ZipArchive;

        if ($zip->open($request->file('bundle')->getRealPath()) !== true) {
            return response()->json(['error' => 'invalid zip'], 422);
        }

        $temp = sys_get_temp_dir().'/'.uniqid('involvement-unpack-', true);

        try {
            $entries = $this->inspect($zip);
            $idValue = $entries['idValue'];

            if (! mkdir($temp, 0700)) {
                throw new \RuntimeException('Could not create unpack directory');
            }

            foreach ($entries['files'] as $index => $file) {
                $bytes = $zip->getFromIndex($index);

                if ($bytes === false || file_put_contents($temp.'/'.$file, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException("Could not unpack {$file}");
                }
            }

            $installer->validate($temp, $idValue);
            $installer->install($temp, config('ssm_gateway_mock.involvements_path'), $idValue, $request->boolean('set_default'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            $zip->close();

            if (is_dir($temp)) {
                File::deleteDirectory($temp);
            }
        }

        return response()->json(['idValue' => $idValue, 'status' => 'synced']);
    }

    /**
     * @return array{idValue: string, files: array<int, string>} zip index => file name inside the IC folder
     */
    private function inspect(\ZipArchive $zip): array
    {
        $idValue = null;
        $files = [];
        $seen = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || isset($seen[$name])) {
                throw new \InvalidArgumentException('duplicate or unreadable zip entry: '.$name);
            }

            $seen[$name] = true;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);

            if ($opsys === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new \InvalidArgumentException('symlink zip entry: '.$name);
            }

            if (str_contains($name, '\\')) {
                throw new \InvalidArgumentException('unsafe zip entry: '.$name);
            }

            $segments = explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name);

            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new \InvalidArgumentException('unsafe zip entry: '.$name);
                }
            }

            if (! SsmGatewayInvolvementRepository::isSafeId($segments[0])) {
                throw new \InvalidArgumentException('unsafe top-level directory: '.$segments[0]);
            }

            $idValue ??= $segments[0];

            if ($segments[0] !== $idValue) {
                throw new \InvalidArgumentException('zip must contain exactly one top-level directory');
            }

            if (str_ends_with($name, '/') && count($segments) === 1) {
                continue;
            }

            $file = $segments[1] ?? null;
            $allowed = $file === 'meta.json' || (str_ends_with((string) $file, '.pdf') && InvolvementVariant::isValid(substr($file, 0, -4)));

            if (count($segments) !== 2 || str_ends_with($name, '/') || ! $allowed) {
                throw new \InvalidArgumentException('unexpected zip entry: '.$name);
            }

            $files[$i] = $file;
        }

        if ($idValue === null) {
            throw new \InvalidArgumentException('empty zip');
        }

        return ['idValue' => $idValue, 'files' => $files];
    }
}
