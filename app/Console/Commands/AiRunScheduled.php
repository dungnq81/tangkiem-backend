<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScheduleFrequency;
use App\Models\Setting;
use App\Models\Story;
use App\Services\Ai\AiSeoGenerator;
use App\Services\Ai\AiService;
use App\Services\Ai\AiSummarizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auto-generate AI content and SEO for stories on a schedule.
 *
 * Two tasks:
 * 1. Content generation: stories that have NO content → AiSummarizer
 * 2. SEO generation: stories that HAVE content but NO SEO metadata → AiSeoGenerator
 *
 * Frequency: Configurable via System Settings (uses ScheduleFrequency enum).
 * Works with both server cron (schedule:work) and web cron (JS heartbeat).
 *
 * Each task uses Cache::add() for frequency throttling — prevents duplicate
 * runs even when both server cron and web cron are active simultaneously.
 */
class AiRunScheduled extends Command
{
    protected $signature = 'ai:run-scheduled';

    protected $description = 'Auto-generate AI content and SEO for stories that need it';

    /**
     * Available frequencies for UI — delegates to ScheduleFrequency enum.
     *
     * @deprecated Use ScheduleFrequency::options() directly.
     */
    public const SCHEDULE_FREQUENCIES = 'use_enum';

    /**
     * Get schedule frequency options for UI dropdowns.
     *
     * @return array<string, string>
     */
    public static function frequencyOptions(): array
    {
        return ScheduleFrequency::options();
    }

    public function handle(): int
    {
        // Global AI must be enabled
        if (! AiService::isEnabled('auto_summary')) {
            $this->line('AI auto_summary is disabled. Skipping.');

            return self::SUCCESS;
        }

        $contentProcessed = $this->runContentGeneration();
        $seoProcessed = $this->runSeoGeneration();

        if ($contentProcessed === 0 && $seoProcessed === 0) {
            $this->line('No AI tasks were due or no stories to process.');
        }

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════
    // Content Generation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate content for stories that don't have any.
     *
     * @return int Number of stories processed
     */
    protected function runContentGeneration(): int
    {
        $enabled = (bool) Setting::get('system.ai_content_enabled', false);

        if (! $enabled) {
            return 0;
        }

        // Cheap DB check first — don't consume cache lock if nothing to do
        $needsContent = Story::query()
            ->where(function ($q) {
                $q->whereNull('content')->orWhere('content', '');
            })
            ->where('is_published', true)
            ->exists();

        if (! $needsContent) {
            return 0;
        }

        $frequency = Setting::get('system.ai_content_frequency', 'hourly');
        $batchSize = (int) Setting::get('system.ai_content_batch_size', 3);
        $intervalMinutes = ScheduleFrequency::tryFrom($frequency)?->intervalMinutes() ?? 60;

        // Frequency throttle via cache — only consumed when stories actually need processing
        if (! Cache::add('ai_schedule:content', true, $intervalMinutes * 60)) {
            return 0;
        }

        $stories = Story::query()
            ->where(function ($q) {
                $q->whereNull('content')->orWhere('content', '');
            })
            ->where('is_published', true)
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($stories->isEmpty()) {
            // Edge case: stories were processed between exists() and get()
            return 0;
        }

        $this->info("Generating content for {$stories->count()} stories...");

        $processed = 0;
        $summarizer = app(AiSummarizer::class);

        foreach ($stories as $story) {
            if (! $story instanceof Story) {
                Log::warning('AI scheduled: Unexpected non-Story object in collection', [
                    'type' => get_class($story),
                ]);

                continue;
            }

            try {
                $content = $summarizer->generate($story);
                $story->update(['content' => $content]);

                $processed++;

                $this->info("  ✅ #{$story->id} {$story->title}");

                Log::info('AI scheduled: Content generated', [
                    'story_id' => $story->id,
                    'title'    => $story->title,
                ]);

                // Small delay between API calls to avoid rate limiting
                if ($processed < $stories->count()) {
                    sleep(2);
                }
            } catch (\Throwable $e) {
                $this->warn("  ❌ #{$story->id} {$story->title}: {$e->getMessage()}");

                Log::warning('AI scheduled: Content generation failed', [
                    'story_id' => $story->id,
                    'title'    => $story->title,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info("Content generation done. Processed: {$processed}/{$stories->count()}");

        return $processed;
    }

    // ═══════════════════════════════════════════════════════════════
    // SEO Generation
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate SEO for stories that have content but no SEO metadata.
     *
     * @return int Number of stories processed
     */
    protected function runSeoGeneration(): int
    {
        $enabled = (bool) Setting::get('system.ai_seo_enabled', false);

        if (! $enabled) {
            return 0;
        }

        // Cheap DB check first — don't consume cache lock if nothing to do
        $needsSeo = Story::query()
            ->where(function ($q) {
                $q->whereNotNull('content')->where('content', '!=', '');
            })
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('meta_title')->orWhere('meta_title', '');
                })->orWhere(function ($sub) {
                    $sub->whereNull('meta_description')->orWhere('meta_description', '');
                });
            })
            ->where('is_published', true)
            ->exists();

        if (! $needsSeo) {
            return 0;
        }

        $frequency = Setting::get('system.ai_seo_frequency', 'hourly');
        $batchSize = (int) Setting::get('system.ai_seo_batch_size', 5);
        $intervalMinutes = ScheduleFrequency::tryFrom($frequency)?->intervalMinutes() ?? 60;

        // Frequency throttle via cache — only consumed when stories actually need processing
        if (! Cache::add('ai_schedule:seo', true, $intervalMinutes * 60)) {
            return 0;
        }

        // Stories that have content but are missing SEO metadata
        $stories = Story::query()
            ->where(function ($q) {
                $q->whereNotNull('content')->where('content', '!=', '');
            })
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('meta_title')->orWhere('meta_title', '');
                })->orWhere(function ($sub) {
                    $sub->whereNull('meta_description')->orWhere('meta_description', '');
                });
            })
            ->where('is_published', true)
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($stories->isEmpty()) {
            // Edge case: stories were processed between exists() and get()
            return 0;
        }

        $this->info("Generating SEO for {$stories->count()} stories...");

        $processed = 0;
        $seoGenerator = app(AiSeoGenerator::class);

        foreach ($stories as $story) {
            if (! $story instanceof Story) {
                Log::warning('AI scheduled: Unexpected non-Story object in SEO collection', [
                    'type' => get_class($story),
                ]);

                continue;
            }

            try {
                $seo = $seoGenerator->generate($story);

                $story->update([
                    'meta_title'       => $seo['meta_title'],
                    'meta_description' => $seo['meta_description'],
                    'meta_keywords'    => $seo['meta_keywords'],
                ]);

                $processed++;

                $this->info("  ✅ #{$story->id} {$story->title}");

                Log::info('AI scheduled: SEO generated', [
                    'story_id'   => $story->id,
                    'title'      => $story->title,
                    'meta_title' => $seo['meta_title'],
                ]);

                // Small delay between API calls
                if ($processed < $stories->count()) {
                    sleep(2);
                }
            } catch (\Throwable $e) {
                $this->warn("  ❌ #{$story->id} {$story->title}: {$e->getMessage()}");

                Log::warning('AI scheduled: SEO generation failed', [
                    'story_id' => $story->id,
                    'title'    => $story->title,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info("SEO generation done. Processed: {$processed}/{$stories->count()}");

        return $processed;
    }
}
