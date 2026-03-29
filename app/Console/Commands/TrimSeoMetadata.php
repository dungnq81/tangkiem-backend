<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SeoLimits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trim meta_title and meta_description that exceed SeoLimits in all content tables.
 *
 * One-time fix for data written by ScrapeImporter which used Str::limit($text, 250, '...')
 * producing up to 253 chars, exceeding the form validation maxLength(250).
 *
 * Safe to run multiple times — only updates rows that currently exceed limits.
 */
class TrimSeoMetadata extends Command
{
    protected $signature = 'seo:trim-metadata {--dry-run : Show counts without updating}';

    protected $description = 'Trim meta_title/meta_description exceeding SeoLimits in all content tables';

    /** Tables with meta_title (VARCHAR 255) and meta_description (TEXT) columns. */
    private const TABLES = ['authors', 'categories', 'stories', 'chapters'];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $maxTitle = SeoLimits::MAX_TITLE;
        $maxDescription = SeoLimits::MAX_DESCRIPTION;

        if ($isDryRun) {
            $this->components->info('DRY RUN — no changes will be made.');
        }

        $this->components->info("Limits: meta_title ≤ {$maxTitle}, meta_description ≤ {$maxDescription}");
        $this->newLine();

        $totalFixed = 0;

        foreach (self::TABLES as $table) {
            $prefix = DB::getTablePrefix();
            $fullTable = $prefix . $table;

            // Count rows exceeding limits
            $titleCount = DB::table($table)
                ->whereRaw("CHAR_LENGTH(meta_title) > ?", [$maxTitle])
                ->count();

            $descCount = DB::table($table)
                ->whereRaw("CHAR_LENGTH(meta_description) > ?", [$maxDescription])
                ->count();

            if ($titleCount === 0 && $descCount === 0) {
                $this->components->twoColumnDetail($table, '<fg=green>OK — no rows exceed limits</>');

                continue;
            }

            if ($isDryRun) {
                $this->components->twoColumnDetail(
                    $table,
                    "<fg=yellow>meta_title: {$titleCount}, meta_description: {$descCount} rows to fix</>"
                );
                $totalFixed += $titleCount + $descCount;

                continue;
            }

            // Fix meta_title: LEFT() truncates cleanly (no suffix needed for titles)
            if ($titleCount > 0) {
                DB::table($table)
                    ->whereRaw("CHAR_LENGTH(meta_title) > ?", [$maxTitle])
                    ->update(['meta_title' => DB::raw("LEFT(meta_title, {$maxTitle})")]);
            }

            // Fix meta_description: LEFT() truncates cleanly
            if ($descCount > 0) {
                DB::table($table)
                    ->whereRaw("CHAR_LENGTH(meta_description) > ?", [$maxDescription])
                    ->update(['meta_description' => DB::raw("LEFT(meta_description, {$maxDescription})")]);
            }

            $this->components->twoColumnDetail(
                $table,
                "<fg=green>Fixed — meta_title: {$titleCount}, meta_description: {$descCount}</>"
            );
            $totalFixed += $titleCount + $descCount;
        }

        $this->newLine();

        if ($isDryRun) {
            $this->components->info("Total rows to fix: {$totalFixed}");
        } else {
            $this->components->info("Done. Total rows fixed: {$totalFixed}");
        }

        return self::SUCCESS;
    }
}
