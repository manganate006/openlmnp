<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DocumentExportService
{
    private const TYPE_MAP = [
        Expense::class      => 'charges',
        Furniture::class    => 'mobilier',
        PropertyWork::class => 'travaux',
    ];

    /**
     * Export all documents as a ZIP file, organized by year and type.
     *
     * @return array{path: string, count: int}
     */
    public function exportZip(User $user, ?int $year = null, ?string $type = null): array
    {
        $documents = $this->getDocuments($user, $year, $type);

        if ($documents->isEmpty()) {
            return ['path' => null, 'count' => 0];
        }

        $zipPath = 'temp/justificatifs-' . $user->id . '-' . now()->format('Ymd-His') . '.zip';
        $zipFullPath = Storage::path($zipPath);

        // Ensure temp directory exists
        Storage::makeDirectory('temp');

        $zip = new ZipArchive();
        if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier ZIP.');
        }

        $usedNames = [];
        $count = 0;

        foreach ($documents as $document) {
            $filePath = Storage::path($document->file_path);
            if (! file_exists($filePath)) {
                continue;
            }

            $docYear = $this->getDocumentYear($document);
            $typeFolder = self::TYPE_MAP[$document->documentable_type] ?? 'autre';
            $ext = pathinfo($document->file_path, PATHINFO_EXTENSION);
            $date = $document->document_date?->format('Y-m-d')
                ?? $this->getParentDate($document)?->format('Y-m-d')
                ?? 'sans-date';
            $slug = Str::slug($document->label);
            $baseName = "{$date}_{$slug}.{$ext}";

            $zipEntryPath = "justificatifs/{$docYear}/{$typeFolder}/{$baseName}";

            // Deduplicate
            if (isset($usedNames[$zipEntryPath])) {
                $usedNames[$zipEntryPath]++;
                $zipEntryPath = "justificatifs/{$docYear}/{$typeFolder}/{$date}_{$slug}-" . $usedNames[$zipEntryPath] . ".{$ext}";
            } else {
                $usedNames[$zipEntryPath] = 1;
            }

            $zip->addFile($filePath, $zipEntryPath);
            $count++;
        }

        $zip->close();

        if ($count === 0) {
            @unlink($zipFullPath);
            return ['path' => null, 'count' => 0];
        }

        return ['path' => $zipPath, 'count' => $count];
    }

    private function getDocuments(User $user, ?int $year, ?string $type): \Illuminate\Support\Collection
    {
        // Get all property IDs belonging to the user
        $propertyIds = Property::where('user_id', $user->id)->pluck('id');

        if ($propertyIds->isEmpty()) {
            return collect();
        }

        // Build a list of documentable type/id pairs
        $query = Document::query();

        $typeFilter = match ($type) {
            'expense'   => [Expense::class],
            'furniture' => [Furniture::class],
            'work'      => [PropertyWork::class],
            default     => [Expense::class, Furniture::class, PropertyWork::class],
        };

        $query->whereIn('documentable_type', $typeFilter);

        // Filter by entities belonging to user's properties
        $query->where(function ($q) use ($propertyIds, $typeFilter) {
            foreach ($typeFilter as $modelClass) {
                $table = (new $modelClass)->getTable();
                $ids = \Illuminate\Support\Facades\DB::table($table)
                    ->whereIn('property_id', $propertyIds)
                    ->pluck('id');

                $q->orWhere(function ($sub) use ($modelClass, $ids) {
                    $sub->where('documentable_type', $modelClass)
                        ->whereIn('documentable_id', $ids);
                });
            }
        });

        if ($year) {
            $query->where(function ($q) use ($year) {
                $q->whereYear('document_date', $year)
                    ->orWhereNull('document_date');
            });
        }

        return $query->with('documentable')->get();
    }

    private function getDocumentYear(Document $document): string
    {
        if ($document->document_date) {
            return (string) $document->document_date->year;
        }

        $parentDate = $this->getParentDate($document);
        return $parentDate ? (string) $parentDate->year : 'sans-annee';
    }

    private function getParentDate(Document $document): ?\Carbon\Carbon
    {
        $parent = $document->documentable;

        if ($parent instanceof Expense) {
            return $parent->expense_date;
        }
        if ($parent instanceof Furniture) {
            return $parent->purchase_date;
        }
        if ($parent instanceof PropertyWork) {
            return $parent->work_date;
        }

        return null;
    }
}
