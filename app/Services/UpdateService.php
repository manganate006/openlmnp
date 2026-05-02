<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class UpdateService
{
    private const GITHUB_API = 'https://api.github.com/repos/';

    private function repo(): string
    {
        return Setting::get('github_repo')
            ?? config('services.github.repo', 'manganate006/openlmnp');
    }

    private function githubHeaders(): array
    {
        $token = Setting::get('github_token')
            ?? config('services.github.token');

        return $token
            ? ['Authorization' => "Bearer {$token}", 'Accept' => 'application/vnd.github+json']
            : ['Accept' => 'application/vnd.github+json'];
    }

    // -------------------------------------------------------------------------
    // Version locale
    // -------------------------------------------------------------------------

    public function getCurrentVersion(): string
    {
        $data = $this->readVersionFile();

        return $data['version'] ?? '0.0.0';
    }

    public function getCurrentCommit(): ?string
    {
        $data = $this->readVersionFile();

        return $data['commit'] ?? null;
    }

    private function readVersionFile(): array
    {
        $path = base_path('version.json');
        if (! file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function writeVersionFile(array $merge): void
    {
        $path = base_path('version.json');
        $data = $this->readVersionFile();
        $data = array_merge($data, $merge, ['updated_at' => now()->toIso8601String()]);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // -------------------------------------------------------------------------
    // Déploiement branche (développement)
    // -------------------------------------------------------------------------

    public function checkBranchUpdates(string $branch = 'main'): array
    {
        try {
            $localCommit = $this->getCurrentCommit();

            // Si pas de commit local, on récupère les derniers commits
            if (! $localCommit) {
                return $this->fetchRecentCommits($branch);
            }

            // Comparer le commit local avec la branche distante
            $response = Http::timeout(10)
                ->withHeaders($this->githubHeaders())
                ->get(self::GITHUB_API . $this->repo() . "/compare/{$localCommit}...{$branch}");

            if (! $response->successful()) {
                // Si le commit local n'existe plus, fallback sur les derniers commits
                if ($response->status() === 404) {
                    return $this->fetchRecentCommits($branch);
                }

                return [
                    'available' => false,
                    'error' => $this->errorMessage($response->status()),
                ];
            }

            $data = $response->json();
            $commits = collect($data['commits'] ?? [])->map(fn ($c) => [
                'sha' => substr($c['sha'], 0, 7),
                'message' => strtok($c['commit']['message'] ?? '', "\n"),
                'date' => $c['commit']['author']['date'] ?? null,
                'author' => $c['commit']['author']['name'] ?? '',
            ])->reverse()->values()->toArray();

            return [
                'available' => ($data['ahead_by'] ?? 0) > 0,
                'local_commit' => substr($localCommit, 0, 7),
                'remote_commit' => substr($data['commits'][count($data['commits']) - 1]['sha'] ?? '', 0, 7),
                'ahead_by' => $data['ahead_by'] ?? 0,
                'commits' => $commits,
                'branch' => $branch,
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => 'Erreur de connexion : ' . $e->getMessage(),
            ];
        }
    }

    private function fetchRecentCommits(string $branch): array
    {
        $response = Http::timeout(10)
            ->withHeaders($this->githubHeaders())
            ->get(self::GITHUB_API . $this->repo() . '/commits', [
                'sha' => $branch,
                'per_page' => 10,
            ]);

        if (! $response->successful()) {
            return [
                'available' => false,
                'error' => $this->errorMessage($response->status()),
            ];
        }

        $data = $response->json();
        $commits = collect($data)->map(fn ($c) => [
            'sha' => substr($c['sha'], 0, 7),
            'message' => strtok($c['commit']['message'] ?? '', "\n"),
            'date' => $c['commit']['author']['date'] ?? null,
            'author' => $c['commit']['author']['name'] ?? '',
        ])->toArray();

        return [
            'available' => true,
            'local_commit' => null,
            'remote_commit' => $commits[0]['sha'] ?? null,
            'ahead_by' => null, // inconnu sans commit de référence
            'commits' => $commits,
            'branch' => $branch,
            'first_deploy' => true,
        ];
    }

    public function applyBranchUpdate(string $branch = 'main'): array
    {
        // Récupérer le SHA du dernier commit avant le déploiement
        $commitSha = null;
        try {
            $response = Http::timeout(10)
                ->withHeaders($this->githubHeaders())
                ->get(self::GITHUB_API . $this->repo() . '/commits/' . $branch);
            if ($response->successful()) {
                $commitSha = $response->json()['sha'] ?? null;
            }
        } catch (\Exception) {
            // On continue même si on ne peut pas récupérer le SHA
        }

        $tarballUrl = self::GITHUB_API . $this->repo() . '/tarball/' . $branch;
        $result = $this->applyFromTarball($tarballUrl);

        if ($result['success'] && $commitSha) {
            $this->writeVersionFile([
                'commit' => $commitSha,
                'branch' => $branch,
            ]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Releases (production)
    // -------------------------------------------------------------------------

    public function checkForUpdates(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders($this->githubHeaders())
                ->get(self::GITHUB_API . $this->repo() . '/releases/latest');

            if (! $response->successful()) {
                if ($response->status() === 404) {
                    $repoCheck = Http::timeout(5)
                        ->withHeaders($this->githubHeaders())
                        ->get(self::GITHUB_API . $this->repo());

                    return [
                        'available' => false,
                        'current_version' => $this->getCurrentVersion(),
                        'info' => $repoCheck->successful() ? 'no_release' : null,
                        'error' => $repoCheck->successful() ? null : 'Repo introuvable. Vérifiez GITHUB_REPO dans .env.',
                    ];
                }

                return [
                    'available' => false,
                    'current_version' => $this->getCurrentVersion(),
                    'error' => $this->errorMessage($response->status()),
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
        $result = $this->applyFromTarball($downloadUrl);

        if ($result['success']) {
            // Refresh version from the newly deployed version.json
            $this->writeVersionFile([]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Rollback
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    private function applyFromTarball(string $tarballUrl): array
    {
        $backupDir = storage_path('app/backups/pre-update-' . date('Ymd-His'));
        $tmpDir = storage_path('app/tmp-update');
        $appDir = base_path();

        try {
            // 1. Télécharger le tarball
            $tarball = $tmpDir . '/release.tar.gz';
            @mkdir($tmpDir, 0755, true);

            Http::timeout(120)
                ->withHeaders($this->githubHeaders())
                ->withOptions(['sink' => $tarball])
                ->get($tarballUrl);

            if (! file_exists($tarball) || filesize($tarball) < 1000) {
                return ['success' => false, 'error' => 'Échec du téléchargement'];
            }

            // 2. Backup
            @mkdir($backupDir, 0755, true);
            Process::path($appDir)->run("cp -r app config database routes version.json composer.json composer.lock {$backupDir}/ 2>/dev/null || true");

            // 3. Extraire
            $extractDir = $tmpDir . '/extracted';
            @mkdir($extractDir, 0755, true);
            Process::path($tmpDir)->run('tar xzf release.tar.gz -C extracted --strip-components=1');

            // 4. Appliquer (préserver .env, storage, vendor, base de données)
            $preserveList = ['.env', 'storage', 'vendor', 'node_modules', 'database/database.sqlite'];
            $rsyncExcludes = implode(' ', array_map(fn ($p) => "--exclude='{$p}'", $preserveList));
            Process::path($tmpDir)->run("rsync -a {$rsyncExcludes} extracted/ {$appDir}/");

            // 5. Commandes post-déploiement
            Process::path($appDir)->timeout(120)->run('composer install --no-dev --optimize-autoloader 2>&1');
            Process::path($appDir)->timeout(60)->run('php artisan migrate --force 2>&1');
            Process::path($appDir)->timeout(30)->run('php artisan optimize:clear 2>&1');

            // 6. Nettoyage
            Process::run("rm -rf {$tmpDir}");

            return [
                'success' => true,
                'backup_path' => $backupDir,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_path' => $backupDir,
            ];
        }
    }

    private function errorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Token GitHub invalide. Vérifiez GITHUB_TOKEN dans .env.',
            403 => 'Accès refusé. Configurez le token GitHub dans les paramètres ci-dessous.',
            404 => 'Repo ou ressource introuvable. Vérifiez GITHUB_REPO dans .env.',
            default => 'Impossible de contacter GitHub (HTTP ' . $status . ')',
        };
    }
}
