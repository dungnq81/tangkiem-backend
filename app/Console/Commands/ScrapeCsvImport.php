<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\ScrapeCsvImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ScrapeCsvImport extends Command
{
    protected $signature = 'scrape:csv-import
        {csv           : Path to CSV file (absolute or relative to storage/app)}
        {--source=     : ScrapeSource ID}
        {--template=   : Template ScrapeJob ID (entity_type=chapter, same source)}
        {--origin=     : Story origin (china, vietnam, korea...). Default: config default}
        {--status=     : Story status (ongoing, completed...). Default: config default}
        {--categories= : Category IDs, comma-separated (e.g. 1,3,5)}
        {--primary-category= : Primary category ID}
        {--published   : Publish stories immediately}
        {--dry-run     : Preview only, do not create anything}';

    protected $description = 'Bulk import stories + chapter scrape jobs from a CSV file';

    public function handle(): int
    {
        $csvPath = $this->resolvePath($this->argument('csv'));

        // ─── Validate CSV file ──────────────────────────────────────
        if (! File::exists($csvPath)) {
            error("CSV file not found: {$csvPath}");

            return self::FAILURE;
        }

        // ─── Validate source ────────────────────────────────────────
        $sourceId = $this->option('source');
        if (! $sourceId) {
            error('--source is required. Use the ScrapeSource ID.');

            return self::FAILURE;
        }

        $source = ScrapeSource::find($sourceId);
        if (! $source) {
            error("ScrapeSource #{$sourceId} not found.");

            return self::FAILURE;
        }

        // ─── Validate template job ──────────────────────────────────
        $templateId = $this->option('template');
        if (! $templateId) {
            error('--template is required. Use a ScrapeJob ID (entity_type=chapter) as config template.');

            return self::FAILURE;
        }

        $templateJob = ScrapeJob::find($templateId);
        if (! $templateJob) {
            error("ScrapeJob #{$templateId} not found.");

            return self::FAILURE;
        }

        if ($templateJob->entity_type !== ScrapeJob::ENTITY_CHAPTER) {
            error("Template job #{$templateId} must have entity_type=chapter (got: {$templateJob->entity_type}).");

            return self::FAILURE;
        }

        if ((int) $templateJob->source_id !== (int) $source->id) {
            error("Template job source_id ({$templateJob->source_id}) doesn't match --source ({$source->id}).");

            return self::FAILURE;
        }

        // ─── Build story defaults ───────────────────────────────────
        $storyDefaults = $this->buildStoryDefaults();

        $isDryRun = $this->option('dry-run');

        // ─── Show summary ───────────────────────────────────────────
        info("📋 Source: {$source->name} (#{$source->id})");
        info("📋 Template: {$templateJob->name} (#{$templateJob->id})");
        info("📄 CSV: {$csvPath}");

        if ($isDryRun) {
            warning('🔍 DRY RUN — no records will be created.');
        }

        $this->newLine();

        // ─── Execute import ─────────────────────────────────────────
        $importer = new ScrapeCsvImporter();
        $result = $importer->import($csvPath, $source, $templateJob, $storyDefaults, $isDryRun);

        // ─── Display results ────────────────────────────────────────
        if (! empty($result->rows)) {
            table(
                ['#', 'Truyện', 'URL', 'Story', 'Status'],
                array_map(fn ($row) => [
                    $row['line'],
                    Str::limit($row['title'], 30),
                    Str::limit($row['url'], 40),
                    $row['story'],
                    $row['status'],
                ], $result->rows),
            );
        }

        $this->newLine();
        $verb = $isDryRun ? '🔍 Would' : '✅';
        info("{$verb} {$result->summary()}");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build story defaults from command options.
     */
    private function buildStoryDefaults(): array
    {
        $defaults = [];

        if ($this->option('origin')) {
            $defaults['origin'] = $this->option('origin');
        }

        if ($this->option('status')) {
            $defaults['status'] = $this->option('status');
        }

        if ($this->option('categories')) {
            $defaults['category_ids'] = array_map('intval', explode(',', $this->option('categories')));
        }

        if ($this->option('primary-category')) {
            $defaults['primary_category_id'] = (int) $this->option('primary-category');
        }

        $defaults['is_published'] = $this->option('published');

        return $defaults;
    }

    /**
     * Resolve file path (absolute or relative to storage/app).
     */
    private function resolvePath(string $path): string
    {
        if (preg_match('#^([a-zA-Z]:\\\\|/)#', $path)) {
            return $path;
        }

        return storage_path("app/{$path}");
    }
}
