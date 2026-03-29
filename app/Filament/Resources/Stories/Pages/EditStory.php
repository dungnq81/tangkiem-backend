<?php

declare(strict_types=1);

namespace App\Filament\Resources\Stories\Pages;

use App\Filament\Resources\Stories\StoryResource;
use App\Models\Story;
use App\Services\Ai\AiService;
use App\Services\Ai\Generators\AiStoryCompositeGenerator;
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

            // ── AI Actions ───────────────────────────────
            $this->getAiContentAction(),
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
    // AI Content + SEO Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * AI Cập nhật nội dung — generates Content + SEO in one call.
     *
     * Fills form fields for user review before saving.
     */
    protected function getAiContentAction(): Action
    {
        return Action::make('ai_content')
            ->label('Cập nhật nội dung')
            ->icon(Heroicon::OutlinedBolt)
            ->color('primary')
            ->visible(fn (): bool => AiService::isEnabled('auto_summary'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBolt)
            ->modalHeading('AI Cập nhật nội dung')
            ->modalDescription('AI sẽ tạo nội dung giới thiệu và SEO metadata. Kết quả điền vào form để bạn review trước khi lưu.')
            ->action(function (): void {
                /** @var Story $record */
                $record = $this->record;

                try {
                    $startTime = microtime(true);

                    $result = app(AiStoryCompositeGenerator::class)->generate($record);

                    // Fill form data
                    $this->data['content'] = $result['content'];
                    $this->data['meta_title'] = $result['meta_title'];
                    $this->data['meta_description'] = $result['meta_description'];
                    $this->data['meta_keywords'] = $result['meta_keywords'];

                    $elapsed = round(microtime(true) - $startTime, 1);
                    $contentLen = mb_strlen(strip_tags($result['content']));

                    Notification::make()
                        ->title('✅ Đã cập nhật nội dung')
                        ->body(
                            "📝 Nội dung: {$contentLen} ký tự" .
                            "\n🔍 SEO: {$result['meta_title']}" .
                            "\n⏱️ {$elapsed}s (1 API call)" .
                            "\n📋 Hãy review và bấm Lưu."
                        )
                        ->success()
                        ->duration(8000)
                        ->send();

                    Log::info('AI Story Composite completed', [
                        'story_id'    => $record->id,
                        'content_len' => $contentLen,
                        'elapsed'     => $elapsed,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('AI Story Composite failed', [
                        'story_id' => $record->id,
                        'error'    => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title('❌ Cập nhật thất bại')
                        ->body($e->getMessage())
                        ->danger()
                        ->duration(10000)
                        ->send();
                }
            });
    }

    // ═══════════════════════════════════════════════════════════════
    // AI Cover Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * AI Tạo ảnh bìa — generates a cover image.
     * Saves directly to DB (images are files, not form text).
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
                    ? '⚠️ Ảnh bìa hiện tại sẽ bị thay thế bằng ảnh mới.'
                    : 'AI tạo ảnh bìa từ tiêu đề, thể loại và mô tả (tỉ lệ 2:3).';
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

        $parts[] = 'Style: Digital painting, vivid colors, cinematic lighting, dramatic atmosphere, professional book cover art quality.';
        $parts[] = 'Art direction: Semi-realistic illustration with rich details, warm color palette, subtle gradients, and a sense of depth.';
        $parts[] = 'Important: Do NOT include any text, title, author name, or watermark on the image. Pure illustration only.';

        return implode(' ', $parts);
    }
}
