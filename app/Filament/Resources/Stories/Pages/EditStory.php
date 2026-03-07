<?php

declare(strict_types=1);

namespace App\Filament\Resources\Stories\Pages;

use App\Enums\StoryOrigin;

use App\Filament\Resources\Stories\StoryResource;
use App\Models\Story;
use App\Services\Ai\AiCategorizer;
use App\Services\Ai\AiService;
use App\Services\Ai\AiSeoGenerator;
use App\Services\Ai\AiSummarizer;
use Awcodes\Curator\Models\Media;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EditStory extends EditRecord
{
    protected static string $resource = StoryResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Story $record */
        $record = $this->record;

        // Find prev/next stories (by created_at desc, same as list sort)
        $prevStory = Story::where('created_at', '>', $record->created_at)
            ->orderBy('created_at', 'asc')
            ->first(['id']);

        $nextStory = Story::where('created_at', '<', $record->created_at)
            ->orderBy('created_at', 'desc')
            ->first(['id']);

        return [
            // ── Navigation ──────────────────────────────────
            Action::make('prev')
                ->label('Trước')
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->url($prevStory
                    ? StoryResource::getUrl('edit', ['record' => $prevStory])
                    : null)
                ->disabled(! $prevStory),

            Action::make('next')
                ->label('Sau')
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->url($nextStory
                    ? StoryResource::getUrl('edit', ['record' => $nextStory])
                    : null)
                ->disabled(! $nextStory),

            // ── AI Composite Action ─────────────────────────
            $this->getAiAllAction(),

            // ── AI Individual Actions ───────────────────────
            $this->getAiClassifyAction(),
            $this->getAiDescriptionAction(),
            $this->getAiSeoAction(),
            $this->getAiCoverAction(),

            // ── Standard Actions ────────────────────────────
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        /** @var Story $record */
        $record = $this->record;

        $categoryName = $record->categories->first()?->name;

        $breadcrumbs = [
            StoryResource::getUrl() => 'Truyện',
        ];

        if ($categoryName) {
            $breadcrumbs[] = $categoryName;
        }

        $breadcrumbs[] = Str::limit($record->title, 40);

        return $breadcrumbs;
    }

    // ═══════════════════════════════════════════════════════════════
    // AI Composite Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * T0: AI Tổng hợp — runs Classify → Content → SEO sequentially.
     *
     * Shows combined results at the end.
     */
    protected function getAiAllAction(): Action
    {
        $canContent = AiService::isEnabled('auto_summary');

        return Action::make('ai_all')
            ->label('AI Tổng hợp')
            ->icon(Heroicon::OutlinedBolt)
            ->color('primary')
            ->visible(fn (): bool => $canContent)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBolt)
            ->modalHeading('AI Tổng hợp — Tạo nội dung + SEO')
            ->modalDescription(function (): string {
                /** @var Story $record */
                $record = $this->record;

                $steps = [];

                $hasContent = ! empty($record->content);
                $steps[] = $hasContent
                    ? '📝 Nội dung: ⏭️ Đã có (sẽ ghi đè)'
                    : '📝 Nội dung: ✅ Sẽ chạy';

                $hasMeta = ! empty($record->meta_title);
                $steps[] = $hasMeta
                    ? '🔍 SEO: ⏭️ Đã có (sẽ ghi đè)'
                    : '🔍 SEO: ✅ Sẽ chạy';

                return "Các bước sẽ thực hiện:\n" . implode("\n", $steps) .
                    "\n\n⚡ Mỗi bước có retry tự động + fallback provider nếu lỗi.\n" .
                    "📋 Kết quả điền vào form — bạn review rồi bấm Lưu.\n" .
                    '💡 Phân loại (thể loại, tags) dùng nút riêng để tham khảo.';
            })
            ->action(function (): void {
                /** @var Story $record */
                $record = $this->record;

                $results = [];  // ['step' => 'message']
                $errors = [];   // ['step' => 'error message']
                $startTime = microtime(true);

                // ── Step 1: Tạo nội dung ─────────────────────
                try {
                    $stepStart = microtime(true);
                    $content = app(AiSummarizer::class)->generate($record);
                    $this->data['content'] = $content;

                    $elapsed = round(microtime(true) - $stepStart, 1);
                    $contentLen = mb_strlen(strip_tags($content));
                    $results['content'] = "📝 Nội dung ({$elapsed}s): {$contentLen} ký tự";
                } catch (\Throwable $e) {
                    $errors['content'] = "📝 Nội dung: {$e->getMessage()}";
                    Log::warning('AI All: content failed', ['error' => $e->getMessage()]);
                }

                // ── Step 2: SEO ──────────────────────────────
                try {
                    $stepStart = microtime(true);

                    // If content was just generated, update the record temporarily
                    // so SEO generator sees the fresh content
                    if (isset($this->data['content']) && ! empty($this->data['content'])) {
                        $record->content = $this->data['content'];
                    }

                    $seo = app(AiSeoGenerator::class)->generate($record);
                    $this->data['meta_title'] = $seo['meta_title'];
                    $this->data['meta_description'] = $seo['meta_description'];
                    $this->data['meta_keywords'] = $seo['meta_keywords'];

                    $elapsed = round(microtime(true) - $stepStart, 1);
                    $results['seo'] = "🔍 SEO ({$elapsed}s): {$seo['meta_title']}";
                } catch (\Throwable $e) {
                    $errors['seo'] = "🔍 SEO: {$e->getMessage()}";
                    Log::warning('AI All: SEO failed', ['error' => $e->getMessage()]);
                }

                // ── Summary notification ─────────────────────
                $totalElapsed = round(microtime(true) - $startTime, 1);
                $successCount = count($results);
                $errorCount = count($errors);

                $body = implode("\n", array_merge(array_values($results), array_values($errors)));
                $body .= "\n\n⏱️ Tổng thời gian: {$totalElapsed}s";

                if ($errorCount === 0) {
                    Notification::make()
                        ->title("✅ AI Tổng hợp hoàn tất ({$successCount}/{$successCount})")
                        ->body($body . "\n\n📋 Đã điền vào form. Hãy review và bấm Lưu.")
                        ->success()
                        ->duration(8000)
                        ->send();
                } elseif ($successCount > 0) {
                    Notification::make()
                        ->title("⚠️ AI Tổng hợp một phần ({$successCount}/" . ($successCount + $errorCount) . ')')
                        ->body($body . "\n\n📋 Các bước thành công đã điền vào form.")
                        ->warning()
                        ->duration(10000)
                        ->send();
                } else {
                    Notification::make()
                        ->title('❌ AI Tổng hợp thất bại')
                        ->body($body)
                        ->danger()
                        ->duration(10000)
                        ->send();
                }

                Log::info('AI All completed', [
                    'story_id' => $record->id,
                    'success'  => $successCount,
                    'errors'   => $errorCount,
                    'elapsed'  => $totalElapsed,
                ]);
            });
    }

    /**
     * Apply AI classify result to form data.
     *
     * @param  array<string, mixed>  $result
     */
    private function applyClassifyResult(array $result): void
    {
        // Merge categories (add, don't remove existing)
        if (! empty($result['categories'])) {
            $existing = array_map('intval', $this->data['categories'] ?? []);
            $merged = array_values(array_unique(array_merge($existing, $result['categories'])));
            $this->data['categories'] = $merged;
        }

        // Set primary category if not already set
        if (! empty($result['primary_category_id']) && empty($this->data['primary_category_id'])) {
            $this->data['primary_category_id'] = $result['primary_category_id'];
        }

        // Merge tags (add, don't remove existing)
        $allTagIds = array_merge(
            $result['tags'] ?? [],
            $result['warning_tags'] ?? [],
            $result['attribute_tags'] ?? [],
        );
        if (! empty($allTagIds)) {
            $existing = array_map('intval', $this->data['tags'] ?? []);
            $merged = array_values(array_unique(array_merge($existing, $allTagIds)));
            $this->data['tags'] = $merged;
        }

        // Set origin
        if (! empty($result['origin'])) {
            $this->data['origin'] = $result['origin'];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // AI Individual Actions
    // ═══════════════════════════════════════════════════════════════

    /**
     * T1: AI Phân loại — suggests categories, tags, type, origin.
     * Chỉ điền vào form, KHÔNG lưu DB. User tự review và bấm Lưu.
     */
    protected function getAiClassifyAction(): Action
    {
        return Action::make('ai_classify')
            ->label('Phân loại')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('info')
            ->visible(fn (): bool => AiService::isEnabled('auto_categorize'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading('AI Gợi ý phân loại')
            ->modalDescription('AI sẽ phân tích tiêu đề + mô tả để gợi ý: thể loại, tags, loại truyện, nguồn gốc. Dữ liệu sẽ được điền vào form để bạn review trước khi lưu.')
            ->action(function (): void {
                /** @var Story $record */
                $record = $this->record;

                try {
                    $startTime = microtime(true);
                    $categorizer = app(AiCategorizer::class);
                    $result = $categorizer->suggest(
                        $record->title,
                        $record->description,
                        $record->author?->name,
                    );

                    $this->applyClassifyResult($result);
                    $elapsed = round(microtime(true) - $startTime, 1);

                    $categoryNames = implode(', ', $result['category_names'] ?? []);
                    $tagNames = implode(', ', $result['tag_names'] ?? []);

                    Notification::make()
                        ->title('AI đã gợi ý phân loại')
                        ->body(
                            "📋 Dữ liệu đã điền vào form ({$elapsed}s). Hãy review và bấm Lưu." .
                            ($categoryNames ? "\n📂 Thể loại: {$categoryNames}" : '') .
                            ($tagNames ? "\n🏷️ Tags: {$tagNames}" : '') .
                            ($result['origin'] ? "\n🌍 Nguồn: " . StoryOrigin::from($result['origin'])->label() : '')
                        )
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Lỗi AI Phân loại')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * T2: AI Tạo nội dung chi tiết — generates story content from chapters.
     * Chỉ điền vào form, KHÔNG lưu DB. User tự review và bấm Lưu.
     */
    protected function getAiDescriptionAction(): Action
    {
        return Action::make('ai_description')
            ->label('Tạo nội dung')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('info')
            ->visible(fn (): bool => AiService::isEnabled('auto_summary'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedDocumentText)
            ->modalHeading('AI Tạo nội dung chi tiết')
            ->modalDescription(function (): string {
                $hasContent = ! empty($this->record->content);
                $chapterCount = $this->record->chapters()->count();

                if ($hasContent) {
                    return '⚠️ Truyện đã có nội dung chi tiết. AI sẽ điền nội dung mới vào form để bạn review.';
                }

                if ($chapterCount > 0) {
                    return "AI sẽ đọc nội dung {$chapterCount} chương đầu để tạo nội dung giới thiệu.";
                }

                return '⚠️ Truyện chưa có chương nào trong hệ thống. AI sẽ tìm kiếm trên internet để tạo nội dung giới thiệu.';
            })
            ->action(function (): void {
                /** @var Story $record */
                $record = $this->record;

                try {
                    $startTime = microtime(true);
                    $summarizer = app(AiSummarizer::class);
                    $content = $summarizer->generate($record);
                    $elapsed = round(microtime(true) - $startTime, 1);

                    $this->data['content'] = $content;

                    Notification::make()
                        ->title('AI đã tạo nội dung')
                        ->body("Nội dung chi tiết đã được điền vào form ({$elapsed}s). Hãy review và bấm Lưu.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Lỗi AI Tạo nội dung')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * T3: AI SEO — generates meta_title, meta_description, meta_keywords.
     * Chỉ điền vào form, KHÔNG lưu DB. User tự review và bấm Lưu.
     */
    protected function getAiSeoAction(): Action
    {
        return Action::make('ai_seo')
            ->label('SEO')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('info')
            ->visible(fn (): bool => AiService::isEnabled('auto_summary'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedMagnifyingGlass)
            ->modalHeading('AI Tạo SEO Metadata')
            ->modalDescription(function (): string {
                $hasMeta = ! empty($this->record->meta_title) || ! empty($this->record->meta_description);
                $hasContent = ! empty($this->record->content) || ! empty($this->record->description);

                if ($hasMeta) {
                    return '⚠️ Truyện đã có SEO metadata. AI sẽ tạo mới để bạn review.';
                }

                if ($hasContent) {
                    return 'AI sẽ phân tích tiêu đề, thể loại và nội dung để tối ưu SEO.';
                }

                return '⚠️ Truyện chưa có nội dung. AI sẽ tìm kiếm trên internet để tạo SEO metadata.';
            })
            ->action(function (): void {
                /** @var Story $record */
                $record = $this->record;

                try {
                    $startTime = microtime(true);
                    $seoGenerator = app(AiSeoGenerator::class);
                    $seo = $seoGenerator->generate($record);
                    $elapsed = round(microtime(true) - $startTime, 1);

                    $this->data['meta_title'] = $seo['meta_title'];
                    $this->data['meta_description'] = $seo['meta_description'];
                    $this->data['meta_keywords'] = $seo['meta_keywords'];

                    Notification::make()
                        ->title('AI đã tạo SEO metadata')
                        ->body(
                            "📋 Title: {$seo['meta_title']}" .
                            "\n📝 Description: " . Str::limit($seo['meta_description'], 80) .
                            '\n🔑 Keywords: ' . Str::limit($seo['meta_keywords'], 80) .
                            "\n\n✅ Đã điền vào form ({$elapsed}s). Hãy review và bấm Lưu."
                        )
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Lỗi AI SEO')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
    /**
     * T4: AI Tạo ảnh bìa — generates a cover image using Gemini Image Generation.
     * Lưu trực tiếp vào DB (không qua form vì ảnh là file, không phải text).
     */
    protected function getAiCoverAction(): Action
    {
        return Action::make('ai_cover')
            ->label('Ảnh bìa')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('warning')
            ->visible(fn (): bool => AiService::isEnabled('cover_generation'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPhoto)
            ->modalHeading('AI Tạo ảnh bìa')
            ->modalDescription(function (): string {
                $hasCover = ! empty($this->record->cover_image_id);

                return $hasCover
                    ? '⚠️ Truyện đã có ảnh bìa. AI sẽ tạo ảnh mới và thay thế.'
                    : 'AI sẽ tạo ảnh bìa dựa trên tiêu đề, thể loại và mô tả (tỉ lệ sách 2:3).';
            })
            ->action(function (): void {
                /** @var Story $record */
                $record = $this->record;

                try {
                    $prompt = $this->buildCoverPrompt($record);
                    $disk = config('curator.default_disk', 'public');

                    Log::info('AI Cover: Generating cover image', [
                        'story_id' => $record->id,
                        'title'    => $record->title,
                    ]);

                    // Generate image via Gemini (returns base64)
                    $aiService = app(AiService::class);
                    $base64Image = $aiService->callImage($prompt, 'cover');

                    // Decode and save to storage
                    $imageData = base64_decode($base64Image);
                    $filename = 'ai-covers/' . date('Y/m') . '/' . Str::random(20) . '.png';

                    Storage::disk($disk)->put($filename, $imageData);

                    // Create Curator Media record
                    $media = Media::create([
                        'disk'      => $disk,
                        'directory' => dirname($filename),
                        'name'      => pathinfo($filename, PATHINFO_FILENAME),
                        'path'      => $filename,
                        'ext'       => 'png',
                        'type'      => 'image/png',
                        'size'      => strlen($imageData),
                        'title'     => "Ảnh bìa: {$record->title}",
                        'alt'       => "Ảnh bìa truyện {$record->title}",
                    ]);

                    // Update story record directly
                    $record->update(['cover_image_id' => $media->id]);
                    $this->data['cover_image_id'] = $media->id;

                    Notification::make()
                        ->title('Đã tạo ảnh bìa')
                        ->body("🎨 Ảnh bìa đã được tạo và lưu thành công.\n📐 Tỉ lệ: 2:3 (book cover)")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Log::error('AI Cover generation failed', [
                        'story_id' => $record->id,
                        'error'    => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title('Lỗi AI Tạo ảnh bìa')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build a detailed prompt for AI cover image generation.
     */
    protected function buildCoverPrompt(Story $record): string
    {
        $categories = $record->categories->pluck('name')->implode(', ');
        $origin = $record->origin?->label() ?? '';
        $description = Str::limit($record->description ?? '', 300);

        $parts = [
            "Create a professional book cover illustration for a novel titled \"{$record->title}\".",
        ];

        if ($record->author?->name) {
            $parts[] = "Author: {$record->author->name}.";
        }

        if ($categories) {
            $parts[] = "Genre: {$categories}.";
        }

        if ($origin) {
            $parts[] = "Origin: {$origin}.";
        }

        if ($description) {
            $parts[] = "Story synopsis: {$description}";
        }

        $parts[] = 'Style: Vivid, cinematic, professional book cover art. High detail, dramatic lighting, rich colors.';
        $parts[] = 'Important: Do NOT include any text, title, or watermark on the image. Pure illustration only.';

        return implode(' ', $parts);
    }
}
