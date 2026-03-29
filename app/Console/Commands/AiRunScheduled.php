<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScheduleFrequency;
use App\Models\Author;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Story;
use App\Services\Ai\AiService;
use App\Services\Ai\Generators\AiAuthorCompositeGenerator;
use App\Services\Ai\Generators\AiCategoryCompositeGenerator;
use App\Services\Ai\Generators\AiStoryCompositeGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auto-generate AI content + SEO for stories, authors, and categories on a schedule.
 *
 * Three composite tasks (each = 1 API call per item):
 * 1. Story: content + SEO → AiStoryCompositeGenerator
 * 2. Author: bio + description + social_links + SEO → AiAuthorCompositeGenerator
 * 3. Category: description + content + SEO → AiCategoryCompositeGenerator
 *
 * Frequency: Configurable via AI Settings page (uses ScheduleFrequency enum).
 * Works with both server cron (schedule:work) and web cron (JS heartbeat).
 *
 * Each task uses Cache::add() for frequency throttling — prevents duplicate
 * runs even when both server cron and web cron are active simultaneously.
 */
class AiRunScheduled extends Command
{
    protected $signature = 'ai:run-scheduled';

    protected $description = 'Auto-generate AI content + SEO for stories, authors, and categories (composite, 1 API call each)';

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

        $storyProcessed = $this->runStoryGeneration();
        $authorProcessed = $this->runAuthorGeneration();
        $categoryProcessed = $this->runCategoryGeneration();

        $total = $storyProcessed + $authorProcessed + $categoryProcessed;

        if ($total === 0) {
            $this->line('No AI tasks were due or no items to process.');
        }

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════
    // Story Content + SEO (Composite)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate content + SEO for published stories missing content OR SEO.
     *
     * Uses AiStoryCompositeGenerator (1 API call = content + SEO).
     * Only fills empty fields — never overwrites existing data.
     */
    protected function runStoryGeneration(): int
    {
        $enabled = (bool) Setting::get('system.ai_story_content_enabled', false);

        if (! $enabled) {
            return 0;
        }

        // Stories that need either content or SEO
        $needsWork = Story::query()
            ->where('is_published', true)
            ->where(function ($q) {
                // Missing content
                $q->where(function ($sub) {
                    $sub->whereNull('content')->orWhere('content', '');
                })
                // OR missing SEO
                ->orWhere(function ($sub) {
                    $sub->where(function ($s) {
                        $s->whereNull('meta_title')->orWhere('meta_title', '');
                    })->orWhere(function ($s) {
                        $s->whereNull('meta_description')->orWhere('meta_description', '');
                    });
                });
            })
            ->exists();

        if (! $needsWork) {
            return 0;
        }

        $frequency = Setting::get('system.ai_story_content_frequency', 'hourly');
        $batchSize = (int) Setting::get('system.ai_story_content_batch_size', 3);
        $intervalMinutes = ScheduleFrequency::tryFrom($frequency)?->intervalMinutes() ?? 60;

        if (! Cache::add('ai_schedule:story_composite', true, $intervalMinutes * 60)) {
            return 0;
        }

        $stories = Story::query()
            ->where('is_published', true)
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('content')->orWhere('content', '');
                })
                ->orWhere(function ($sub) {
                    $sub->where(function ($s) {
                        $s->whereNull('meta_title')->orWhere('meta_title', '');
                    })->orWhere(function ($s) {
                        $s->whereNull('meta_description')->orWhere('meta_description', '');
                    });
                });
            })
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($stories->isEmpty()) {
            return 0;
        }

        $this->info("Generating content + SEO for {$stories->count()} stories...");

        $processed = 0;
        $generator = app(AiStoryCompositeGenerator::class);

        foreach ($stories as $story) {
            if (! $story instanceof Story) {
                continue;
            }

            try {
                $result = $generator->generate($story);

                // Only fill empty fields — never overwrite existing data
                $updates = [];
                if (empty($story->content) && ! empty($result['content'])) {
                    $updates['content'] = $result['content'];
                }
                if (empty($story->meta_title) && ! empty($result['meta_title'])) {
                    $updates['meta_title'] = $result['meta_title'];
                }
                if (empty($story->meta_description) && ! empty($result['meta_description'])) {
                    $updates['meta_description'] = $result['meta_description'];
                }
                if (empty($story->meta_keywords) && ! empty($result['meta_keywords'])) {
                    $updates['meta_keywords'] = $result['meta_keywords'];
                }

                if (! empty($updates)) {
                    $story->update($updates);
                    $processed++;

                    $this->info("  ✅ #{$story->id} {$story->title} (" . implode(', ', array_keys($updates)) . ')');

                    Log::info('AI scheduled: Story composite generated', [
                        'story_id' => $story->id,
                        'title'    => $story->title,
                        'fields'   => array_keys($updates),
                    ]);
                }

                if ($processed < $stories->count()) {
                    sleep(2);
                }
            } catch (\Throwable $e) {
                $this->warn("  ❌ #{$story->id} {$story->title}: {$e->getMessage()}");

                Log::warning('AI scheduled: Story composite failed', [
                    'story_id' => $story->id,
                    'title'    => $story->title,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info("Story generation done. Processed: {$processed}/{$stories->count()}");

        return $processed;
    }

    // ═══════════════════════════════════════════════════════════════
    // Author Content + SEO (Composite)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate bio + description + social_links + SEO for authors.
     *
     * Uses AiAuthorCompositeGenerator (1 API call = all fields).
     * Only fills empty fields — never overwrites existing data.
     */
    protected function runAuthorGeneration(): int
    {
        if (! AiService::isEnabled('auto_author_content')) {
            return 0;
        }

        $enabled = (bool) Setting::get('system.ai_author_content_enabled', false);

        if (! $enabled) {
            return 0;
        }

        // Authors missing bio or SEO
        $needsWork = Author::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('bio')->orWhere('bio', '');
                })
                ->orWhere(function ($sub) {
                    $sub->where(function ($s) {
                        $s->whereNull('meta_title')->orWhere('meta_title', '');
                    })->orWhere(function ($s) {
                        $s->whereNull('meta_description')->orWhere('meta_description', '');
                    });
                });
            })
            ->exists();

        if (! $needsWork) {
            return 0;
        }

        $frequency = Setting::get('system.ai_author_content_frequency', 'hourly');
        $batchSize = (int) Setting::get('system.ai_author_content_batch_size', 3);
        $intervalMinutes = ScheduleFrequency::tryFrom($frequency)?->intervalMinutes() ?? 60;

        if (! Cache::add('ai_schedule:author_composite', true, $intervalMinutes * 60)) {
            return 0;
        }

        $authors = Author::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('bio')->orWhere('bio', '');
                })
                ->orWhere(function ($sub) {
                    $sub->where(function ($s) {
                        $s->whereNull('meta_title')->orWhere('meta_title', '');
                    })->orWhere(function ($s) {
                        $s->whereNull('meta_description')->orWhere('meta_description', '');
                    });
                });
            })
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($authors->isEmpty()) {
            return 0;
        }

        $this->info("Generating content + SEO for {$authors->count()} authors...");

        $processed = 0;
        $generator = app(AiAuthorCompositeGenerator::class);

        foreach ($authors as $author) {
            if (! $author instanceof Author) {
                continue;
            }

            try {
                $result = $generator->generate($author);

                // Only fill empty fields — never overwrite existing data
                $updates = [];
                if (empty($author->bio) && ! empty($result['bio'])) {
                    $updates['bio'] = $result['bio'];
                }
                if (empty($author->description) && ! empty($result['description'])) {
                    $updates['description'] = $result['description'];
                }
                if (empty($author->social_links) && ! empty($result['social_links'])) {
                    $updates['social_links'] = $result['social_links'];
                }
                if (empty($author->meta_title) && ! empty($result['meta_title'])) {
                    $updates['meta_title'] = $result['meta_title'];
                }
                if (empty($author->meta_description) && ! empty($result['meta_description'])) {
                    $updates['meta_description'] = $result['meta_description'];
                }

                if (! empty($updates)) {
                    $author->update($updates);
                    $processed++;

                    $this->info("  ✅ #{$author->id} {$author->name} (" . implode(', ', array_keys($updates)) . ')');

                    Log::info('AI scheduled: Author composite generated', [
                        'author_id' => $author->id,
                        'name'      => $author->name,
                        'fields'    => array_keys($updates),
                    ]);
                }

                if ($processed < $authors->count()) {
                    sleep(3); // Author uses internet search, needs more delay
                }
            } catch (\Throwable $e) {
                $this->warn("  ❌ #{$author->id} {$author->name}: {$e->getMessage()}");

                Log::warning('AI scheduled: Author composite failed', [
                    'author_id' => $author->id,
                    'name'      => $author->name,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->info("Author generation done. Processed: {$processed}/{$authors->count()}");

        return $processed;
    }

    // ═══════════════════════════════════════════════════════════════
    // Category Content + SEO (Composite)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate description + content + SEO for active categories.
     *
     * Uses AiCategoryCompositeGenerator (1 API call = all fields).
     * Only fills empty fields — never overwrites existing data.
     */
    protected function runCategoryGeneration(): int
    {
        if (! AiService::isEnabled('auto_category_content')) {
            return 0;
        }

        $enabled = (bool) Setting::get('system.ai_category_content_enabled', false);

        if (! $enabled) {
            return 0;
        }

        // Categories missing content
        $needsWork = Category::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('content')->orWhere('content', '');
            })
            ->exists();

        if (! $needsWork) {
            return 0;
        }

        $frequency = Setting::get('system.ai_category_content_frequency', 'hourly');
        $batchSize = (int) Setting::get('system.ai_category_content_batch_size', 3);
        $intervalMinutes = ScheduleFrequency::tryFrom($frequency)?->intervalMinutes() ?? 60;

        if (! Cache::add('ai_schedule:category_composite', true, $intervalMinutes * 60)) {
            return 0;
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('content')->orWhere('content', '');
            })
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($categories->isEmpty()) {
            return 0;
        }

        $this->info("Generating content + SEO for {$categories->count()} categories...");

        $processed = 0;
        $generator = app(AiCategoryCompositeGenerator::class);

        foreach ($categories as $category) {
            if (! $category instanceof Category) {
                continue;
            }

            try {
                $result = $generator->generate($category);

                $updates = [];
                if (empty($category->description) && ! empty($result['description'])) {
                    $updates['description'] = $result['description'];
                }
                if (empty($category->content) && ! empty($result['content'])) {
                    $updates['content'] = $result['content'];
                }
                if (empty($category->meta_title) && ! empty($result['meta_title'])) {
                    $updates['meta_title'] = $result['meta_title'];
                }
                if (empty($category->meta_description) && ! empty($result['meta_description'])) {
                    $updates['meta_description'] = $result['meta_description'];
                }

                if (! empty($updates)) {
                    $category->update($updates);
                    $processed++;

                    $this->info("  ✅ #{$category->id} {$category->name} (" . implode(', ', array_keys($updates)) . ')');

                    Log::info('AI scheduled: Category composite generated', [
                        'category_id' => $category->id,
                        'name'        => $category->name,
                        'fields'      => array_keys($updates),
                    ]);
                }

                if ($processed < $categories->count()) {
                    sleep(3);
                }
            } catch (\Throwable $e) {
                $this->warn("  ❌ #{$category->id} {$category->name}: {$e->getMessage()}");

                Log::warning('AI scheduled: Category composite failed', [
                    'category_id' => $category->id,
                    'name'        => $category->name,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $this->info("Category generation done. Processed: {$processed}/{$categories->count()}");

        return $processed;
    }
}
