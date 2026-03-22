<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Services\Ai\AiService;
use App\Services\Ai\Generators\AiCategoryCompositeGenerator;
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

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Category $record */
        $record = $this->record;

        // Find prev/next categories (by sort_order, then name)
        $prevCategory = Category::where(function ($q) use ($record) {
            $q->where('sort_order', '<', $record->sort_order)
              ->orWhere(function ($q2) use ($record) {
                  $q2->where('sort_order', $record->sort_order)
                     ->where('name', '<', $record->name);
              });
        })
            ->orderBy('sort_order', 'desc')
            ->orderBy('name', 'desc')
            ->first(['id']);

        $nextCategory = Category::where(function ($q) use ($record) {
            $q->where('sort_order', '>', $record->sort_order)
              ->orWhere(function ($q2) use ($record) {
                  $q2->where('sort_order', $record->sort_order)
                     ->where('name', '>', $record->name);
              });
        })
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->first(['id']);

        return [
            // ── Navigation ─────────────────────────────────
            Action::make('prev')
                ->label('')
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->size('sm')
                ->tooltip('Thể loại trước')
                ->url($prevCategory ? CategoryResource::getUrl('edit', ['record' => $prevCategory->id]) : null)
                ->visible((bool) $prevCategory),

            Action::make('next')
                ->label('')
                ->icon(Heroicon::OutlinedChevronRight)
                ->color('gray')
                ->size('sm')
                ->tooltip('Thể loại sau')
                ->url($nextCategory ? CategoryResource::getUrl('edit', ['record' => $nextCategory->id]) : null)
                ->visible((bool) $nextCategory),

            // ── AI Actions ───────────────────────────────
            $this->getAiContentAction(),
            $this->getAiImageAction(),

            // ── Standard Actions ────────────────────────────
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // AI Content + SEO Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * AI Cập nhật nội dung — generates description + content + SEO in ONE API call.
     * Fills form fields for user review before saving.
     */
    protected function getAiContentAction(): Action
    {
        return Action::make('ai_content')
            ->label('Cập nhật nội dung')
            ->icon(Heroicon::OutlinedBolt)
            ->color('primary')
            ->visible(fn (): bool => AiService::isEnabled('auto_category_content'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBolt)
            ->modalHeading('AI Cập nhật nội dung thể loại')
            ->modalDescription('AI tìm kiếm thông tin thể loại trên internet → tạo mô tả, nội dung chi tiết và SEO. Kết quả điền vào form để bạn review.')
            ->action(function (): void {
                /** @var Category $record */
                $record = $this->record;

                try {
                    $startTime = microtime(true);

                    $result = app(AiCategoryCompositeGenerator::class)->generate($record);

                    // Fill form data
                    $this->data['description'] = $result['description'];
                    $this->data['content'] = $result['content'];
                    $this->data['meta_title'] = $result['meta_title'];
                    $this->data['meta_description'] = $result['meta_description'];

                    $elapsed = round(microtime(true) - $startTime, 1);
                    $descLen = mb_strlen($result['description']);
                    $contentLen = mb_strlen(strip_tags($result['content']));

                    Notification::make()
                        ->title('✅ Đã cập nhật nội dung thể loại')
                        ->body(
                            "📝 Mô tả: {$descLen} ký tự, Chi tiết: {$contentLen} ký tự" .
                            "\n🔍 SEO: {$result['meta_title']}" .
                            "\n⏱️ {$elapsed}s (1 API call)" .
                            "\n📋 Hãy review và bấm Lưu."
                        )
                        ->success()
                        ->duration(8000)
                        ->send();

                    Log::info('AI Category Composite completed', [
                        'category_id' => $record->id,
                        'desc_len'    => $descLen,
                        'content_len' => $contentLen,
                        'elapsed'     => $elapsed,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('AI Category Composite failed', [
                        'category_id' => $record->id,
                        'error'       => $e->getMessage(),
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
    // AI Image Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * AI Tạo ảnh bìa thể loại — generates a category cover image.
     * Saves directly to DB (images are files, not form text).
     */
    protected function getAiImageAction(): Action
    {
        return Action::make('ai_category_image')
            ->label('Ảnh bìa')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('warning')
            ->visible(fn (): bool => AiService::isEnabled('cover_generation'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPhoto)
            ->modalHeading('AI Tạo ảnh bìa thể loại')
            ->modalDescription(function (): string {
                $hasImage = ! empty($this->record->image_id);

                return $hasImage
                    ? '⚠️ Ảnh bìa hiện tại sẽ bị thay thế bằng ảnh mới.'
                    : 'AI tạo ảnh bìa thể loại từ tên và mô tả (tỉ lệ 4:3).';
            })
            ->action(function (): void {
                /** @var Category $record */
                $record = $this->record;

                try {
                    $prompt = $this->buildCategoryImagePrompt($record);
                    $disk = config('curator.default_disk', 'public');

                    Log::info('AI Category Image: Generating', [
                        'category_id' => $record->id,
                        'name'        => $record->name,
                    ]);

                    $aiService = app(AiService::class);
                    $base64Image = $aiService->callImage($prompt, 'landscape');

                    $imageData = base64_decode($base64Image);
                    $filename = 'ai-categories/' . date('Y/m') . '/' . Str::random(20) . '.png';

                    Storage::disk($disk)->put($filename, $imageData);

                    $media = Media::create([
                        'disk'      => $disk,
                        'directory' => dirname($filename),
                        'name'      => pathinfo($filename, PATHINFO_FILENAME),
                        'path'      => $filename,
                        'ext'       => 'png',
                        'type'      => 'image/png',
                        'size'      => strlen($imageData),
                        'title'     => "Ảnh bìa: {$record->name}",
                        'alt'       => "Ảnh bìa thể loại {$record->name}",
                    ]);

                    $record->update(['image_id' => $media->id]);
                    $this->data['image_id'] = $media->id;

                    Notification::make()
                        ->title('Đã tạo ảnh bìa thể loại')
                        ->body("🎨 Ảnh bìa đã được tạo và lưu thành công.\n📐 Tỉ lệ: 4:3 (landscape)")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Log::error('AI Category Image generation failed', [
                        'category_id' => $record->id,
                        'error'       => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title('Lỗi AI Tạo ảnh bìa thể loại')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build prompt for AI category image generation.
     */
    protected function buildCategoryImagePrompt(Category $record): string
    {
        $description = Str::limit($record->description ?? '', 200);
        $storyCount = $record->stories()->count();
        $parentName = $record->parent?->name;

        $parts = [
            "Create a professional, artistic banner illustration representing the literary genre \"{$record->name}\".",
        ];

        if ($parentName) {
            $parts[] = "This is a sub-genre of \"{$parentName}\".";
        }

        if ($description) {
            $parts[] = "Genre description: {$description}";
        }

        if ($storyCount > 0) {
            $parts[] = "This genre contains {$storyCount} stories.";
        }

        $parts[] = 'Style: Digital painting, vivid colors, cinematic lighting, dramatic atmosphere, professional quality.';
        $parts[] = 'Art direction: Semi-realistic illustration with rich details, warm color palette, subtle gradients, and a sense of depth. Evoke the mood of this literary genre.';
        $parts[] = 'Important: Do NOT include any text, title, or watermark on the image. Pure illustration only.';

        return implode(' ', $parts);
    }
}
