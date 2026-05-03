<?php

/**
 * Tests structurels de navigation : vérifient qu'aucune page n'est orpheline
 * et que toutes les pages sont accessibles dans chaque mode de navigation.
 *
 * La carte des liens est maintenue manuellement et doit être mise à jour
 * quand on ajoute/retire une page ou un lien entre pages.
 */

use App\Enums\NavMode;
use App\Filament\Pages\AnnualImportWizard;
use App\Filament\Pages\Badges;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FiscalYearWizard;
use App\Filament\Pages\HelpPage;
use App\Filament\Pages\ImportAirbnb;
use App\Filament\Pages\LoanDetail;
use App\Filament\Pages\OnboardingWizard;
use App\Filament\Pages\Projection;
use App\Filament\Pages\Simulator;
use App\Filament\Pages\Teledeclaration;
use App\Filament\Pages\TvaDeclaration;
use App\Filament\Pages\AdminStats;
use App\Filament\Pages\AdminUpdate;
use App\Filament\Pages\SystemStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Filament\Resources\Furniture\FurnitureResource;
use App\Filament\Resources\Incomes\IncomeResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\PropertyComponents\PropertyComponentResource;
use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use App\Models\User;

// ─────────────────────────────────────────────────────────────────────
// Registry: all user-facing pages (excluding admin-only and profile)
// ─────────────────────────────────────────────────────────────────────

function allUserPages(): array
{
    return [
        'Dashboard' => Dashboard::class,
        'HelpPage' => HelpPage::class,
        'Badges' => Badges::class,
        'Properties' => PropertyResource::class,
        'Incomes' => IncomeResource::class,
        'Expenses' => ExpenseResource::class,
        'Loans' => LoanResource::class,
        'Furniture' => FurnitureResource::class,
        'PropertyWorks' => PropertyWorkResource::class,
        'PropertyComponents' => PropertyComponentResource::class,
        'FiscalYears' => FiscalYearResource::class,
        'Simulator' => Simulator::class,
        'Projection' => Projection::class,
        'Teledeclaration' => Teledeclaration::class,
        'TvaDeclaration' => TvaDeclaration::class,
        'AnnualImportWizard' => AnnualImportWizard::class,
        'ImportAirbnb' => ImportAirbnb::class,
        'LoanDetail' => LoanDetail::class,
        'FiscalYearWizard' => FiscalYearWizard::class,
        'OnboardingWizard' => OnboardingWizard::class,
    ];
}

function adminPages(): array
{
    return [
        'AdminStats' => AdminStats::class,
        'AdminUpdate' => AdminUpdate::class,
        'SystemStatus' => SystemStatus::class,
    ];
}

// ─────────────────────────────────────────────────────────────────────
// Link map: page → pages it links to via actions/buttons/widgets
// ─────────────────────────────────────────────────────────────────────

function pageLinkMap(): array
{
    return [
        'Dashboard' => ['Incomes', 'Expenses', 'Simulator', 'Properties'],
        'Incomes' => ['ImportAirbnb'],
        'Loans' => ['LoanDetail'],
        'FiscalYears' => ['FiscalYearWizard', 'Simulator', 'Projection', 'Teledeclaration'],
    ];
}

function helpPageLinks(): array
{
    return ['ImportAirbnb', 'OnboardingWizard', 'FiscalYearWizard', 'Simulator', 'Teledeclaration', 'Projection'];
}

// ─────────────────────────────────────────────────────────────────────
// Navigation visibility per mode
// ─────────────────────────────────────────────────────────────────────

/** Pages that never appear in any menu ($shouldRegisterNavigation = false). */
function noMenuPages(): array
{
    return ['ImportAirbnb', 'FiscalYearWizard'];
}

/** Pages hidden in Simple mode via isHiddenInSimpleMode(). */
function hiddenInSimpleMode(): array
{
    return ['AnnualImportWizard', 'PropertyComponents', 'PropertyWorks', 'Furniture'];
}

/** Pages with conditional navigation (need specific data to appear). */
function conditionalPages(): array
{
    return ['OnboardingWizard', 'TvaDeclaration'];
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

/** Compute all pages reachable from menu pages, following links transitively. */
function reachablePages(array $menuPages, array $linkMap, array $helpLinks): array
{
    $reachable = $menuPages;
    $queue = $menuPages;

    while ($queue) {
        $current = array_shift($queue);
        $targets = $linkMap[$current] ?? [];

        if ($current === 'HelpPage') {
            $targets = array_merge($targets, $helpLinks);
        }

        foreach ($targets as $target) {
            if (! in_array($target, $reachable)) {
                $reachable[] = $target;
                $queue[] = $target;
            }
        }
    }

    return $reachable;
}

/** Pages visible in a given mode's menu (excluding conditional and no-menu pages). */
function menuPagesForMode(string $mode): array
{
    $all = array_keys(allUserPages());
    $noMenu = noMenuPages();
    $hiddenSimple = hiddenInSimpleMode();
    $conditional = conditionalPages();

    return array_values(array_filter($all, function ($page) use ($mode, $noMenu, $hiddenSimple, $conditional) {
        if (in_array($page, $noMenu)) {
            return false;
        }
        if (in_array($page, $conditional)) {
            return false;
        }
        if ($mode === 'simple' && in_array($page, $hiddenSimple)) {
            return false;
        }

        return true;
    }));
}

/**
 * Pages that should be reachable in a given mode.
 * Pages intentionally hidden in Simple mode are excluded from that mode's expectations.
 */
function expectedReachablePages(string $mode): array
{
    $all = array_keys(allUserPages());
    $conditional = conditionalPages();
    $hiddenSimple = hiddenInSimpleMode();

    return array_values(array_filter($all, function ($page) use ($mode, $conditional, $hiddenSimple) {
        if (in_array($page, $conditional)) {
            return false;
        }
        // In Simple mode, hidden pages are intentionally inaccessible
        if ($mode === 'simple' && in_array($page, $hiddenSimple)) {
            return false;
        }

        return true;
    }));
}

// ─────────────────────────────────────────────────────────────────────
// Tests
// ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('has no fully orphan pages — every page is in at least one menu or has an incoming link', function () {
    $allPages = array_keys(allUserPages());
    $linkMap = pageLinkMap();
    $helpLinks = helpPageLinks();
    $conditional = conditionalPages();

    // All pages that receive incoming links
    $linkedTo = [];
    foreach ($linkMap as $targets) {
        foreach ($targets as $target) {
            $linkedTo[] = $target;
        }
    }
    $linkedTo = array_merge($linkedTo, $helpLinks);
    $linkedTo = array_unique($linkedTo);

    // Menu pages across all modes
    $menuInAnyMode = array_unique(array_merge(
        menuPagesForMode('simple'),
        menuPagesForMode('advanced'),
        menuPagesForMode('guided'),
    ));

    $orphans = [];
    foreach ($allPages as $page) {
        if (in_array($page, $conditional)) {
            continue;
        }
        if (! in_array($page, $menuInAnyMode) && ! in_array($page, $linkedTo)) {
            $orphans[] = $page;
        }
    }

    expect($orphans)->toBe([], 'Orphan pages (no menu, no link): ' . implode(', ', $orphans));
});

it('all pages are reachable in Simple mode', function () {
    $menuPages = menuPagesForMode('simple');
    $reachable = reachablePages($menuPages, pageLinkMap(), helpPageLinks());
    $expected = expectedReachablePages('simple');

    $unreachable = array_values(array_diff($expected, $reachable));

    expect($unreachable)->toBe([], 'Unreachable in Simple mode: ' . implode(', ', $unreachable));
});

it('all pages are reachable in Advanced mode', function () {
    $menuPages = menuPagesForMode('advanced');
    $reachable = reachablePages($menuPages, pageLinkMap(), helpPageLinks());
    $expected = expectedReachablePages('advanced');

    $unreachable = array_values(array_diff($expected, $reachable));

    expect($unreachable)->toBe([], 'Unreachable in Advanced mode: ' . implode(', ', $unreachable));
});

it('all pages are reachable in Guided mode', function () {
    $menuPages = menuPagesForMode('guided');
    $reachable = reachablePages($menuPages, pageLinkMap(), helpPageLinks());
    $expected = expectedReachablePages('guided');

    $unreachable = array_values(array_diff($expected, $reachable));

    expect($unreachable)->toBe([], 'Unreachable in Guided mode: ' . implode(', ', $unreachable));
});

it('no-menu pages all have at least one incoming link', function () {
    $noMenu = noMenuPages();
    $linkMap = pageLinkMap();
    $helpLinks = helpPageLinks();

    $linkedTo = [];
    foreach ($linkMap as $targets) {
        foreach ($targets as $target) {
            $linkedTo[] = $target;
        }
    }
    $linkedTo = array_merge($linkedTo, $helpLinks);
    $linkedTo = array_unique($linkedTo);

    $dangling = array_values(array_diff($noMenu, $linkedTo));

    expect($dangling)->toBe([], 'No-menu pages without incoming link: ' . implode(', ', $dangling));
});

it('help page blade template contains expected href links', function () {
    $helpBlade = file_get_contents(resource_path('views/filament/pages/help.blade.php'));

    $expectedHrefs = [
        '/import-airbnb',
        '/onboarding-wizard',
        '/fiscal-year-wizard',
        '/simulator',
        '/teledeclaration',
        '/projection',
    ];

    foreach ($expectedHrefs as $href) {
        expect($helpBlade)->toContain("href=\"{$href}\"");
    }
});

it('page link map matches actual code — source files contain expected URLs', function () {
    // ListIncomes → ImportAirbnb
    $listIncomes = file_get_contents(app_path('Filament/Resources/Incomes/Pages/ListIncomes.php'));
    expect($listIncomes)->toContain('/import-airbnb');

    // LoansTable → LoanDetail (per-row action)
    $loansTable = file_get_contents(app_path('Filament/Resources/Loans/Tables/LoansTable.php'));
    expect($loansTable)->toContain('LoanDetail::getUrl(');

    // ListFiscalYears → FiscalYearWizard, Simulator, Projection, Teledeclaration
    $listFY = file_get_contents(app_path('Filament/Resources/FiscalYears/Pages/ListFiscalYears.php'));
    expect($listFY)->toContain('FiscalYearWizard::getUrl()');
    expect($listFY)->toContain('Simulator::getUrl()');
    expect($listFY)->toContain('Projection::getUrl()');
    expect($listFY)->toContain('Teledeclaration::getUrl()');

    // Dashboard widget → /incomes, /expenses, /simulator, /properties/create
    $widget = file_get_contents(app_path('Filament/Widgets/FiscalOverview.php'));
    expect($widget)->toContain("'/incomes'");
    expect($widget)->toContain("'/expenses'");
    expect($widget)->toContain("'/simulator'");
    expect($widget)->toContain("'/properties/create'");
});

it('all page classes in the registry actually exist', function () {
    $allPages = array_merge(allUserPages(), adminPages());

    foreach ($allPages as $name => $class) {
        expect(class_exists($class))->toBeTrue("Page class {$class} ({$name}) does not exist");
    }
});

it('link map keys and targets all reference valid page keys', function () {
    $validKeys = array_keys(allUserPages());
    $linkMap = pageLinkMap();
    $helpLinks = helpPageLinks();

    $invalid = [];

    foreach ($linkMap as $source => $targets) {
        if (! in_array($source, $validKeys)) {
            $invalid[] = "source '{$source}'";
        }
        foreach ($targets as $target) {
            if (! in_array($target, $validKeys)) {
                $invalid[] = "{$source} → '{$target}'";
            }
        }
    }

    foreach ($helpLinks as $target) {
        if (! in_array($target, $validKeys)) {
            $invalid[] = "HelpPage → '{$target}'";
        }
    }

    expect($invalid)->toBe([], 'Invalid page references in link map: ' . implode(', ', $invalid));
});
