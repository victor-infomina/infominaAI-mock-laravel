<?php

namespace App\Services\WebhookInbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * File-backed store for webhook inboxes. One folder per inbox:
 *
 *   <root>/<slug>/config.json                  token, identifier, secret, response_mode, created_at
 *   <root>/<slug>/events/<YmdHisu>-<uuid>.json one received request each
 *
 * Files rather than a table because the cPanel deployment has no shell to
 * run migrations on upgrade (DEPLOY.md §1b). Received content is always
 * re-serialised into a file we name; nothing the sender controls becomes a
 * file name, extension or executable.
 */
class WebhookInboxRepository
{
    public const RESPONSE_MODES = ['ok', 'error', 'timeout'];

    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public const MAX_BODY_BYTES = 262144;

    public const MAX_EVENTS = 500;

    private const RETENTION_DAYS = 7;

    private const SETTING_KEYS = ['identifier', 'secret', 'response_mode'];

    /** @return array<int, array> */
    public function all(): array
    {
        if (! File::isDirectory($this->root())) {
            return [];
        }

        $inboxes = [];
        foreach (File::directories($this->root()) as $dir) {
            $inbox = $this->find(basename($dir));
            if ($inbox !== null) {
                $inboxes[] = $inbox;
            }
        }
        usort($inboxes, fn ($a, $b) => strcmp($a['slug'], $b['slug']));

        return $inboxes;
    }

    public function find(string $slug): ?array
    {
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            return null;
        }
        $path = $this->configPath($slug);
        if (! File::exists($path)) {
            return null;
        }
        $config = json_decode(File::get($path), true);
        if (! is_array($config) || ! is_string($config['token'] ?? null)) {
            return null;
        }

        return [
            'slug' => $slug,
            'token' => $config['token'],
            'identifier' => $config['identifier'] ?? null,
            'secret' => $config['secret'] ?? null,
            'response_mode' => $config['response_mode'] ?? 'ok',
            'created_at' => $config['created_at'] ?? null,
        ];
    }

    public function findByToken(string $slug, string $token): ?array
    {
        $inbox = $this->find($slug);
        if ($inbox === null || $token === '' || ! hash_equals($inbox['token'], $token)) {
            return null;
        }

        return $inbox;
    }

    public function create(string $slug, array $settings): array
    {
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new InvalidArgumentException("Invalid inbox slug: {$slug}");
        }
        if ($this->find($slug) !== null) {
            throw new InvalidArgumentException("Inbox already exists: {$slug}");
        }
        $settings = $this->normaliseSettings($settings);

        File::ensureDirectoryExists($this->eventsPath($slug));
        $this->writeConfig($slug, $settings + [
            'token' => $this->newToken(),
            'created_at' => Carbon::now('UTC')->toIso8601String(),
        ]);

        return $this->find($slug);
    }

    public function updateSettings(string $slug, array $settings): array
    {
        $current = $this->find($slug);
        if ($current === null) {
            throw new RuntimeException("Inbox not found: {$slug}");
        }
        $merged = $this->normaliseSettings(
            array_merge(array_intersect_key($current, array_flip(self::SETTING_KEYS)), $settings),
        );
        $this->writeConfig($slug, $merged + ['token' => $current['token'], 'created_at' => $current['created_at']]);

        return $this->find($slug);
    }

    public function regenerateToken(string $slug): array
    {
        $current = $this->find($slug);
        if ($current === null) {
            throw new RuntimeException("Inbox not found: {$slug}");
        }
        $this->writeConfig($slug, array_intersect_key($current, array_flip(self::SETTING_KEYS)) + [
            'token' => $this->newToken(),
            'created_at' => $current['created_at'],
        ]);

        return $this->find($slug);
    }

    public function storeEvent(string $slug, array $event): array
    {
        if ($this->find($slug) === null) {
            throw new RuntimeException("Inbox not found: {$slug}");
        }
        $event['id'] = $event['id'] ?? (string) Str::uuid();
        $event['received_at'] = $event['received_at'] ?? Carbon::now('UTC')->toIso8601String();

        File::ensureDirectoryExists($this->eventsPath($slug));
        $this->purgeExpired($slug);
        $this->enforceCap($slug, self::MAX_EVENTS - 1);

        // Microsecond prefix keeps "newest first" stable for deliveries in the same second.
        $file = $this->eventsPath($slug).'/'.Carbon::now('UTC')->format('YmdHisu').'-'.$event['id'].'.json';
        // Senders control the bytes; invalid UTF-8 is substituted rather than
        // letting json_encode fail and leave an empty, unreadable event file.
        $json = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Could not encode event: '.json_last_error_msg());
        }
        File::put($file, $json);

        return $event;
    }

    /** @return array<int, array> newest first */
    public function events(string $slug, ?string $since = null, ?string $referenceId = null): array
    {
        $dir = $this->eventsPath($slug);
        if (! File::isDirectory($dir)) {
            return [];
        }
        $sinceTs = $this->parseIso($since);

        $events = [];
        foreach ($this->eventFilesNewestFirst($slug) as $file) {
            $event = json_decode(File::get($file->getPathname()), true);
            if (! is_array($event)) {
                continue;
            }
            if ($sinceTs !== null && $this->parseIso($event['received_at'] ?? null) < $sinceTs) {
                continue;
            }
            if ($referenceId !== null && ($event['reference_id'] ?? null) !== $referenceId) {
                continue;
            }
            $events[] = $event;
        }

        return $events;
    }

    public function findEvent(string $slug, string $id): ?array
    {
        foreach ($this->events($slug) as $event) {
            if (($event['id'] ?? null) === $id) {
                return $event;
            }
        }

        return null;
    }

    public function clear(string $slug): int
    {
        $dir = $this->eventsPath($slug);
        if (! File::isDirectory($dir)) {
            return 0;
        }
        $count = 0;
        foreach (File::files($dir) as $file) {
            File::delete($file->getPathname());
            $count++;
        }

        return $count;
    }

    /** @return array<int, \SplFileInfo> */
    private function eventFilesNewestFirst(string $slug): array
    {
        $files = File::files($this->eventsPath($slug));
        usort($files, fn ($a, $b) => strcmp($b->getFilename(), $a->getFilename()));

        return $files;
    }

    private function purgeExpired(string $slug): void
    {
        $cutoff = time() - self::RETENTION_DAYS * 86400;
        foreach (File::files($this->eventsPath($slug)) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
            }
        }
    }

    /** Keep at most $keep files, deleting the oldest. */
    private function enforceCap(string $slug, int $keep): void
    {
        $files = $this->eventFilesNewestFirst($slug);
        foreach (array_slice($files, $keep) as $file) {
            File::delete($file->getPathname());
        }
    }

    private function parseIso(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function normaliseSettings(array $settings): array
    {
        $mode = $settings['response_mode'] ?? 'ok';
        if (! in_array($mode, self::RESPONSE_MODES, true)) {
            throw new InvalidArgumentException("Invalid response mode: {$mode}");
        }

        return [
            'identifier' => $this->blankToNull($settings['identifier'] ?? null),
            'secret' => $this->blankToNull($settings['secret'] ?? null),
            'response_mode' => $mode,
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return ($value === null || trim($value) === '') ? null : trim($value);
    }

    private function writeConfig(string $slug, array $config): void
    {
        File::ensureDirectoryExists($this->inboxPath($slug));
        File::put($this->configPath($slug), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function root(): string
    {
        return rtrim(config('ssm_mock.webhook_inbox_path'), '/');
    }

    private function inboxPath(string $slug): string
    {
        return $this->root().'/'.$slug;
    }

    private function configPath(string $slug): string
    {
        return $this->inboxPath($slug).'/config.json';
    }

    private function eventsPath(string $slug): string
    {
        return $this->inboxPath($slug).'/events';
    }
}
