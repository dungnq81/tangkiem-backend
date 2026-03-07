<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\error;

class ScrapeExportTemplate extends Command
{
    protected $signature = 'scrape:export-template
        {jobId : ID of the ScrapeJob to export as template}
        {--name= : Custom filename (without .json extension)}';

    protected $description = 'Export a ScrapeJob config as a reusable JSON template';

    /**
     * Fields to export into the template (reusable config only).
     */
    private const TEMPLATE_FIELDS = [
        'source_id',
        'entity_type',
        'selectors',
        'ai_prompt',
        'pagination',
        'detail_config',
        'import_defaults',
        'is_scheduled',
        'auto_import',
        'schedule_frequency',
        'schedule_time',
        'schedule_day_of_week',
        'schedule_day_of_month',
    ];

    public function handle(): int
    {
        $jobId = (int) $this->argument('jobId');
        $job = ScrapeJob::with('source')->find($jobId);

        if (!$job) {
            error("ScrapeJob #{$jobId} not found.");
            return self::FAILURE;
        }

        // Extract only template fields
        $template = [];
        $template['_description'] = "Template exported from ScrapeJob #{$job->id} ({$job->name}). Source: {$job->source?->name}.";

        foreach (self::TEMPLATE_FIELDS as $field) {
            $template[$field] = $job->getAttribute($field);
        }

        // Determine filename
        $customName = $this->option('name');
        $filename = $customName
            ? Str::slug($customName)
            : Str::slug($job->source?->name . '-' . $job->entity_type . '-' . $job->name);

        $outputDir = storage_path('app/scrape-templates');
        File::ensureDirectoryExists($outputDir);

        $outputPath = "{$outputDir}/{$filename}.json";

        // Avoid overwriting without notice
        if (File::exists($outputPath)) {
            if (!$this->confirm("File {$filename}.json already exists. Overwrite?")) {
                info('Cancelled.');
                return self::SUCCESS;
            }
        }

        File::put(
            $outputPath,
            json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        info("✅ Template saved to: scrape-templates/{$filename}.json");

        return self::SUCCESS;
    }
}
