<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authors\Pages;

use App\Filament\Resources\Authors\AuthorResource;
use App\Models\Author;
use App\Services\Ai\AiService;
use App\Services\Ai\Generators\AiAuthorCompositeGenerator;
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

class EditAuthor extends EditRecord
{
    protected static string $resource = AuthorResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Author $record */
        $record = $this->record;

        // Find prev/next authors (by created_at desc, same as list sort)
        $prevAuthor = Author::where('created_at', '>', $record->created_at)
            ->orderBy('created_at', 'asc')
            ->first(['id']);

        $nextAuthor = Author::where('created_at', '<', $record->created_at)
            ->orderBy('created_at', 'desc')
            ->first(['id']);

        return [
            // ── Navigation ──────────────────────────────────
            Action::make('prev')
                ->label('Trước')
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->url($prevAuthor
                    ? AuthorResource::getUrl('edit', ['record' => $prevAuthor])
                    : null)
                ->disabled(! $prevAuthor),

            Action::make('next')
                ->label('Sau')
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->url($nextAuthor
                    ? AuthorResource::getUrl('edit', ['record' => $nextAuthor])
                    : null)
                ->disabled(! $nextAuthor),

            // ── AI Actions ──────────────────────────────────
            $this->getAiContentAction(),
            $this->getAiAvatarAction(),

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
     * AI Cập nhật nội dung tác giả — generates Content + SEO in one call.
     *
     * Fills form fields for user review before saving.
     */
    protected function getAiContentAction(): Action
    {
        return Action::make('ai_author_content')
            ->label('Cập nhật nội dung')
            ->icon(Heroicon::OutlinedBolt)
            ->color('primary')
            ->visible(fn (): bool => AiService::isEnabled('auto_author_content'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBolt)
            ->modalHeading('AI Cập nhật nội dung tác giả')
            ->modalDescription('AI tìm kiếm thông tin tác giả trên internet → tạo tiểu sử, thông tin chi tiết, mạng xã hội và SEO. Kết quả điền vào form để bạn review.')
            ->action(function (): void {
                /** @var Author $record */
                $record = $this->record;

                try {
                    $startTime = microtime(true);

                    $result = app(AiAuthorCompositeGenerator::class)->generate($record);

                    // Fill form data
                    $this->data['bio'] = $result['bio'];
                    $this->data['description'] = $result['description'];
                    $this->data['meta_title'] = $result['meta_title'];
                    $this->data['meta_description'] = $result['meta_description'];

                    if (! empty($result['social_links'])) {
                        $this->data['social_links'] = $result['social_links'];
                    }

                    $elapsed = round(microtime(true) - $startTime, 1);
                    $bioLen = mb_strlen(strip_tags($result['bio']));
                    $descLen = mb_strlen(strip_tags($result['description']));
                    $socialCount = count($result['social_links']);

                    Notification::make()
                        ->title('✅ Đã cập nhật nội dung tác giả')
                        ->body(
                            "📝 Nội dung: {$bioLen} + {$descLen} ký tự, {$socialCount} liên kết" .
                            "\n🔍 SEO: {$result['meta_title']}" .
                            "\n⏱️ {$elapsed}s (1 API call)" .
                            "\n📋 Hãy review và bấm Lưu."
                        )
                        ->success()
                        ->duration(8000)
                        ->send();

                    Log::info('AI Author Composite completed', [
                        'author_id' => $record->id,
                        'bio_len'   => $bioLen,
                        'desc_len'  => $descLen,
                        'socials'   => $socialCount,
                        'elapsed'   => $elapsed,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('AI Author Composite failed', [
                        'author_id' => $record->id,
                        'error'     => $e->getMessage(),
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
    // AI Avatar Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * AI Tạo avatar tác giả — generates an avatar image.
     * Saves directly to DB (images are files, not form text).
     */
    protected function getAiAvatarAction(): Action
    {
        return Action::make('ai_avatar')
            ->label('Ảnh đại diện')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('warning')
            ->visible(fn (): bool => AiService::isEnabled('cover_generation'))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPhoto)
            ->modalHeading('AI Tạo ảnh đại diện')
            ->modalDescription(function (): string {
                $hasAvatar = ! empty($this->record->avatar_id);

                return $hasAvatar
                    ? '⚠️ Ảnh đại diện hiện tại sẽ bị thay thế bằng ảnh mới.'
                    : 'AI tạo ảnh đại diện tác giả từ tên và tiểu sử (tỉ lệ 1:1).';
            })
            ->action(function (): void {
                /** @var Author $record */
                $record = $this->record;

                try {
                    $prompt = $this->buildAvatarPrompt($record);
                    $disk = config('curator.default_disk', 'public');

                    Log::info('AI Avatar: Generating avatar', [
                        'author_id' => $record->id,
                        'name'      => $record->name,
                    ]);

                    $aiService = app(AiService::class);
                    $base64Image = $aiService->callImage($prompt, 'thumbnail');

                    $imageData = base64_decode($base64Image);
                    $filename = 'ai-avatars/' . date('Y/m') . '/' . Str::random(20) . '.png';

                    Storage::disk($disk)->put($filename, $imageData);

                    $media = Media::create([
                        'disk'      => $disk,
                        'directory' => dirname($filename),
                        'name'      => pathinfo($filename, PATHINFO_FILENAME),
                        'path'      => $filename,
                        'ext'       => 'png',
                        'type'      => 'image/png',
                        'size'      => strlen($imageData),
                        'title'     => "Avatar: {$record->name}",
                        'alt'       => "Avatar tác giả {$record->name}",
                    ]);

                    $record->update(['avatar_id' => $media->id]);
                    $this->data['avatar_id'] = $media->id;

                    Notification::make()
                        ->title('Đã tạo ảnh đại diện')
                        ->body("🎨 Ảnh đại diện đã được tạo và lưu thành công.\n📐 Tỉ lệ: 1:1 (avatar)")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Log::error('AI Avatar generation failed', [
                        'author_id' => $record->id,
                        'error'     => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title('Lỗi AI Tạo ảnh đại diện')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Build prompt for AI avatar generation.
     */
    protected function buildAvatarPrompt(Author $record): string
    {
        $bio = Str::limit($record->bio ?? '', 200);
        $storyCount = $record->stories()->count();

        $parts = [
            "Create a professional portrait avatar for an author named \"{$record->name}\".",
        ];

        if ($record->original_name) {
            $parts[] = "Also known as: {$record->original_name}.";
        }

        if ($bio) {
            $parts[] = "Author bio: {$bio}";
        }

        if ($storyCount > 0) {
            $parts[] = "This author has written {$storyCount} stories.";
        }

        $parts[] = 'Style: Digital painting, elegant portrait illustration, warm color palette, cinematic lighting, professional quality.';
        $parts[] = 'Art direction: Semi-realistic, intellectual and distinguished appearance. Rich details, subtle gradients, and a sense of depth.';
        $parts[] = 'Important: Do NOT include any text, name, or watermark. Pure illustration only.';

        return implode(' ', $parts);
    }
}
