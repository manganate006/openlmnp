<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use UnitEnum;

class SystemStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;
    protected static string | UnitEnum | null $navigationGroup = 'Paramètres';
    protected static ?string $navigationLabel = 'État du système';
    protected static ?string $title = 'État du système et tests';
    protected static ?int $navigationSort = 5;
    protected string $view = 'filament.pages.system-status';

    public ?array $testResults = null;
    public bool $testsRunning = false;

    public function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'filament_version' => \Composer\InstalledVersions::getVersion('filament/filament') ?? 'N/A',
            'db_driver' => config('database.default'),
            'db_size' => $this->getDatabaseSize(),
            'storage_free' => $this->getStorageFree(),
            'users_count' => DB::table('users')->count(),
            'properties_count' => DB::table('properties')->count(),
            'incomes_count' => DB::table('incomes')->count(),
            'expenses_count' => DB::table('expenses')->count(),
            'fiscal_years_count' => DB::table('fiscal_years')->count(),
            'uptime' => $this->getUptime(),
        ];
    }

    public function runTests(): void
    {
        $this->testsRunning = true;

        $result = Process::timeout(120)
            ->path(base_path())
            ->run('vendor/bin/pest --compact 2>&1');

        $output = $result->output();
        $exitCode = $result->exitCode();

        // Parse le résultat
        $this->testResults = [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'ran_at' => now()->format('d/m/Y H:i:s'),
            'summary' => $this->parseTestSummary($output),
        ];

        $this->testsRunning = false;
    }

    private function parseTestSummary(string $output): array
    {
        $summary = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
        ];

        // Parse JSON output from Pest
        if (preg_match('/(\d+) passed/', $output, $m)) {
            $summary['passed'] = (int) $m[1];
        }
        if (preg_match('/(\d+) failed/', $output, $m)) {
            $summary['failed'] = (int) $m[1];
        }
        if (preg_match('/Tests:\s*(\d+)/', $output, $m)) {
            $summary['total'] = (int) $m[1];
        }

        // Fallback: count lines with ✓ and ✕
        if ($summary['total'] === 0) {
            $summary['passed'] = substr_count($output, '✓') + substr_count($output, 'PASS');
            $summary['failed'] = substr_count($output, '✕') + substr_count($output, 'FAIL');
            $summary['total'] = $summary['passed'] + $summary['failed'];
        }

        return $summary;
    }

    private function getDatabaseSize(): string
    {
        $dbPath = config('database.connections.sqlite.database');
        if ($dbPath && file_exists($dbPath)) {
            $bytes = filesize($dbPath);
            return number_format($bytes / 1024, 0) . ' Ko';
        }
        return 'N/A';
    }

    private function getStorageFree(): string
    {
        $free = disk_free_space(storage_path());
        if ($free === false) return 'N/A';
        return number_format($free / 1024 / 1024, 0) . ' Mo';
    }

    private function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/uptime')) {
            $uptime = (float) file_get_contents('/proc/uptime');
            $days = floor($uptime / 86400);
            $hours = floor(($uptime % 86400) / 3600);
            return "{$days}j {$hours}h";
        }
        return 'N/A';
    }
}
