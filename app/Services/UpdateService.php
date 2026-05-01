<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class UpdateService
{
    private const GITHUB_API = 'https://api.github.com/repos/';

    private function repo(): string
    {
        return config('services.github.repo', 'openlmnp/openlmnp');
    }

    private function githubHeaders(): array
    {
        $token = config('services.github.token');

        return $token
            ? ['Authorization' => "Bearer {$token}", 'Accept' => 'application/vnd.github+json']
            : ['Accept' => 'application/vnd.github+json'];
    }

    public function getCurrentVersion(): string
    {
        $versionFile = base_path('version.json');
        if (! file_exists($versionFile)) {
            return '0.0.0';
        }

        $data = json_decode(file_get_contents($versionFile), true);

        return $data['version'] ?? '0.0.0';
    }

    public function checkForUpdates(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders($this->githubHeaders())
                ->get(self::GITHUB_API . $this->repo() . '/releases/latest');

            if (! $response->successful()) {
                // 404 on /releases/latest means no release yet (repo may exist)
                if ($response->status() === 404) {
                    // Check if repo itself exists
                    $repoCheck = Http::timeout(5)
                        ->withHeaders($this->githubHeaders())
                        ->get(self::GITHUB_API . $this->repo());

                    $msg = $repoCheck->successful()
                        ? 'Aucune release publiee. Vous utilisez la derniere version.'
                        : 'Repo introuvable. Verifiez GITHUB_REPO dans .env.';

                    return [
                        'available' => false,
                        'current_version' => $this->getCurrentVersion(),
                        'info' => $repoCheck->successful() ? 'no_release' : null,
                        'error' => $repoCheck->successful() ? null : $msg,
                    ];
                }

                $msg = match ($response->status()) {
                    403 => 'Acces refuse. Configurez GITHUB_TOKEN dans .env pour un repo prive.',
                    default => 'Impossible de contacter GitHub (HTTP ' . $response->status() . ')',
                };

                return [
                    'available' => false,
                    'current_version' => $this->getCurrentVersion(),
                    'error' => $msg,
                ];
            }

            $release = $response->json();
            $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
            $currentVersion = $this->getCurrentVersion();

            return [
                'available' => version_compare($latestVersion, $currentVersion, '>'),
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'release_name' => $release['name'] ?? $latestVersion,
                'published_at' => $release['published_at'] ?? null,
                'changelog' => $release['body'] ?? '',
                'download_url' => $release['tarball_url'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => 'Erreur de connexion : ' . $e->getMessage(),
            ];
        }
    }

    public function getChangelog(int $limit = 5): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders($this->githubHeaders())
                ->get(self::GITHUB_API . $this->repo() . '/releases', [
                    'per_page' => $limit,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json())->map(fn ($r) => [
                'version' => ltrim($r['tag_name'] ?? '', 'v'),
                'name' => $r['name'] ?? '',
                'published_at' => $r['published_at'] ?? null,
                'changelog' => $r['body'] ?? '',
            ])->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function applyUpdate(string $downloadUrl): array
    {
        $backupDir = storage_path('app/backups/pre-update-' . date('Ymd-His'));
        $tmpDir = storage_path('app/tmp-update');
        $appDir = base_path();

        try {
            // 1. Download tarball
            $tarball = $tmpDir . '/release.tar.gz';
            @mkdir($tmpDir, 0755, true);

            $response = Http::timeout(60)->withOptions(['sink' => $tarball])->get($downloadUrl);
            if (! file_exists($tarball) || filesize($tarball) < 1000) {
                return ['success' => false, 'error' => 'Echec du telechargement'];
            }

            // 2. Backup current version
            @mkdir($backupDir, 0755, true);
            Process::path($appDir)->run("cp -r app config database routes version.json composer.json composer.lock {$backupDir}/");

            // 3. Extract
            $extractDir = $tmpDir . '/extracted';
            @mkdir($extractDir, 0755, true);
            Process::path($tmpDir)->run("tar xzf release.tar.gz -C extracted --strip-components=1");

            // 4. Apply files (preserve .env, storage, vendor)
            $preserveList = ['.env', 'storage', 'vendor', 'node_modules', 'database/database.sqlite'];
            $rsyncExcludes = implode(' ', array_map(fn ($p) => "--exclude='{$p}'", $preserveList));
            Process::path($tmpDir)->run("rsync -a {$rsyncExcludes} extracted/ {$appDir}/");

            // 5. Post-update commands
            Process::path($appDir)->timeout(120)->run('composer install --no-dev --optimize-autoloader 2>&1');
            Process::path($appDir)->timeout(60)->run('php artisan migrate --force 2>&1');
            Process::path($appDir)->timeout(30)->run('php artisan optimize:clear 2>&1');

            // 6. Cleanup
            Process::run("rm -rf {$tmpDir}");

            return [
                'success' => true,
                'backup_path' => $backupDir,
                'previous_version' => $this->getCurrentVersion(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupDir,
            ];
        }
    }

    public function rollback(string $backupPath): array
    {
        if (! is_dir($backupPath)) {
            return ['success' => false, 'error' => 'Backup introuvable'];
        }

        try {
            $appDir = base_path();
            Process::path($appDir)->run("cp -rf {$backupPath}/* {$appDir}/");
            Process::path($appDir)->run('php artisan optimize:clear');

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
