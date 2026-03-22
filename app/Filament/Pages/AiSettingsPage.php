<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ScheduleFrequency;
use App\Models\Setting;
use App\Services\Ai\AiService;
use Illuminate\Support\Facades\Artisan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Admin page for managing AI feature settings.
 *
 * All settings stored in `settings` table (group: 'ai' or 'system').
 */
class AiSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'Cài đặt AI & Hệ thống';

    protected static string | UnitEnum | null $navigationGroup = 'Quản lý hệ thống';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Cài đặt AI & Hệ thống';

    protected static ?string $slug = 'ai-settings';

    protected string $view = 'filament.pages.ai-settings';

    /**
     * Form state.
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // ═══════════════════════════════════════════════════════════════════════
    // Header Actions
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Clear all caches and rebuild optimization caches (no CLI needed).
     *
     * Flow: Clear everything → Rebuild production caches.
     * Equivalent to: /clear-cache workflow + /optimize-production workflow.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshCache')
                ->label('Refresh Cache')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Xóa & tạo lại tất cả cache?')
                ->modalDescription('Sẽ xóa toàn bộ cache (config, route, view, Filament, response, icons, app) rồi tạo lại cache tối ưu cho production.')
                ->modalSubmitActionLabel('Refresh')
                ->action(function (): void {
                    app()->terminating(function (): void {
                        if (function_exists('opcache_reset')) {
                            opcache_reset();
                        }

                        Artisan::call('optimize:clear');

                        $optionalClears = [
                            'filament:optimize-clear',
                            'responsecache:clear',
                            'icons:clear',
                        ];
                        foreach ($optionalClears as $cmd) {
                            try {
                                Artisan::call($cmd);
                            } catch (\Throwable) {
                            }
                        }

                        Artisan::call('cache:clear');

                        Artisan::call('optimize');

                        $optionalRebuilds = [
                            'filament:optimize',
                            'icons:cache',
                        ];
                        foreach ($optionalRebuilds as $cmd) {
                            try {
                                Artisan::call($cmd);
                            } catch (\Throwable) {
                            }
                        }
                    });

                    Notification::make()
                        ->title('✅ Cache đang được refresh!')
                        ->body('Toàn bộ cache sẽ được xóa & tạo lại ngay khi response hoàn tất. Tải lại trang để thấy hiệu lực.')
                        ->success()
                        ->duration(8000)
                        ->send();
                }),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Lifecycle
    // ═══════════════════════════════════════════════════════════════════════

    public function mount(): void
    {
        $cleanPatterns = Setting::get('ai.clean_patterns', []);

        $this->form->fill([
            // AI Global
            'enabled'            => (bool) Setting::get('ai.enabled', false),
            'provider'           => Setting::get('ai.provider', AiService::DEFAULT_PROVIDER),
            'model'              => Setting::get('ai.model', config('ai.providers.' . AiService::DEFAULT_PROVIDER . '.default_model')),

            // AI Features
            'auto_summary'          => (bool) Setting::get('ai.auto_summary', false),
            'auto_author_content'   => (bool) Setting::get('ai.auto_author_content', false),
            'auto_category_content' => (bool) Setting::get('ai.auto_category_content', false),
            'content_clean'         => (bool) Setting::get('ai.content_clean', false),
            'content_moderation'    => (bool) Setting::get('ai.content_moderation', false),
            'cover_generation'      => (bool) Setting::get('ai.cover_generation', false),

            // AI Schedule — Story composite (content + SEO in 1 call)
            'ai_story_content_enabled'    => (bool) Setting::get('system.ai_story_content_enabled', false),
            'ai_story_content_frequency'  => Setting::get('system.ai_story_content_frequency', 'hourly'),
            'ai_story_content_batch_size' => (int) Setting::get('system.ai_story_content_batch_size', 3),

            // AI Schedule — Author composite (content + SEO in 1 call)
            'ai_author_content_enabled'    => (bool) Setting::get('system.ai_author_content_enabled', false),
            'ai_author_content_frequency'  => Setting::get('system.ai_author_content_frequency', 'hourly'),
            'ai_author_content_batch_size' => (int) Setting::get('system.ai_author_content_batch_size', 3),

            // AI Schedule — Category composite (content + SEO in 1 call)
            'ai_category_content_enabled'    => (bool) Setting::get('system.ai_category_content_enabled', false),
            'ai_category_content_frequency'  => Setting::get('system.ai_category_content_frequency', 'hourly'),
            'ai_category_content_batch_size' => (int) Setting::get('system.ai_category_content_batch_size', 3),

            // Clean patterns
            'clean_patterns'     => is_array($cleanPatterns)
                ? $cleanPatterns
                : (json_decode((string) $cleanPatterns, true) ?? []),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Form
    // ═══════════════════════════════════════════════════════════════════════

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                self::globalSection(),
                self::featuresSection(),
                self::aiScheduleSection(),
                self::patternsSection(),
            ])
            ->statePath('data');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function globalSection(): Section
    {
        return Section::make('AI Tổng quan')
            ->description(new HtmlString('
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Bật/tắt AI và chọn provider mặc định cho toàn hệ thống.
                </div>
            '))
            ->schema([
                Toggle::make('enabled')
                    ->label('Bật tính năng AI')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Khi tắt, <strong>TẤT CẢ</strong> tính năng AI đều không hoạt động.</span>
                    '))
                    ->columnSpanFull(),

                Select::make('provider')
                    ->label('Provider mặc định')
                    ->options(function (): array {
                        $providers = [];
                        foreach (config('ai.providers', []) as $key => $cfg) {
                            $providers[$key] = ucfirst($key);
                        }

                        return $providers;
                    })
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $default = config("ai.providers.{$state}.default_model");
                        $set('model', $default);
                    }),

                Select::make('model')
                    ->label('Model mặc định')
                    ->options(function (callable $get): array {
                        $provider = $get('provider') ?: AiService::DEFAULT_PROVIDER;

                        return config("ai.providers.{$provider}.models", []);
                    })
                    ->required()
                    ->native(false)
                    ->searchable(),

                View::make('filament.components.ai-api-guide')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function featuresSection(): Section
    {
        return Section::make('Tính năng AI')
            ->description(new HtmlString('
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Bật/tắt từng tính năng riêng lẻ. Chỉ hoạt động khi <strong>AI tổng quan</strong> đã bật.<br>
                    Nút bấm thủ công trên trang chỉnh sửa + tác vụ tự động (nếu bật ở mục bên dưới).
                </div>
            '))
            ->schema([
                Toggle::make('auto_summary')
                    ->label('Nội dung & SEO truyện')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tạo nội dung giới thiệu + meta title/description cho truyện.</span>
                    ')),

                Toggle::make('auto_author_content')
                    ->label('Nội dung & SEO tác giả')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tìm kiếm internet → tạo tiểu sử, thông tin, mạng xã hội + meta title/description cho tác giả.</span>
                    ')),

                Toggle::make('auto_category_content')
                    ->label('Nội dung & SEO thể loại')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tạo mô tả, nội dung chi tiết + meta title/description cho thể loại truyện.</span>
                    ')),

                Toggle::make('cover_generation')
                    ->label('Tạo ảnh AI')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Ảnh bìa truyện (2:3), avatar tác giả (1:1), ảnh thể loại (4:3) — Gemini Imagen.</span>
                    ')),

                Toggle::make('content_clean')
                    ->label('Dọn dẹp nội dung chương')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Loại bỏ quảng cáo, watermark, text rác trong nội dung chương.</span>
                    ')),

                Toggle::make('content_moderation')
                    ->label('Kiểm duyệt bình luận')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tự động ẩn bình luận spam, toxic, NSFW khi người dùng đăng.</span>
                    ')),
            ])
            ->columns(2);
    }

    private static function patternsSection(): Section
    {
        return Section::make('Patterns dọn nội dung (Toàn cục)')
            ->description(new HtmlString('
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    <ul class="list-disc pl-4 space-y-0.5">
                        <li>Danh sách nội dung cần xóa khỏi <strong>TẤT CẢ</strong> chapters</li>
                        <li>Patterns riêng theo nguồn → cài đặt trong từng <strong>Scrape Source</strong></li>
                    </ul>
                </div>
            '))
            ->schema([
                Repeater::make('clean_patterns')
                    ->label('Patterns')
                    ->schema([
                        TextInput::make('pattern')
                            ->label('Nội dung / Pattern')
                            ->required()
                            ->placeholder('VD: Truyện hay tại truyen.com'),
                        Select::make('type')
                            ->label('Loại')
                            ->options([
                                'text'  => '📝 Text chính xác (exact match)',
                                'regex' => '🔧 Regex pattern',
                            ])
                            ->default('text')
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2)
                    ->addActionLabel('+ Thêm pattern')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ]);
    }

    private static function aiScheduleSection(): Section
    {
        return Section::make('AI Tự động')
            ->description(new HtmlString('
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    <p class="mb-1">Tự động chạy AI tạo nội dung + SEO cho truyện/tác giả/thể loại theo lịch.</p>
                    <ul class="list-disc pl-4 space-y-0.5">
                        <li>Mỗi item = <strong>1 API call</strong> (composite: nội dung + SEO cùng lúc, tiết kiệm token)</li>
                        <li>Chỉ fill fields trống — <strong>không ghi đè</strong> dữ liệu đã có</li>
                        <li>Hoạt động với cả <strong>server cron</strong> và <strong>web cron</strong></li>
                        <li>Yêu cầu AI phải được bật ở mục trên (enabled + tính năng tương ứng)</li>
                    </ul>
                </div>
            '))
            ->schema([
                // ── Story Composite ────────────────────────────
                Grid::make(3)->schema([
                    Toggle::make('ai_story_content_enabled')
                        ->label('Tạo nội dung & SEO truyện tự động')
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Truyện chưa có content hoặc SEO → 1 API call tạo cả hai</span>'
                        ))
                        ->columnSpan(1),

                    Select::make('ai_story_content_frequency')
                        ->label('Tần suất')
                        ->options(ScheduleFrequency::options())
                        ->visible(fn (callable $get) => $get('ai_story_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_story_content_enabled'))
                        ->columnSpan(1),

                    TextInput::make('ai_story_content_batch_size')
                        ->label('Số truyện / lần chạy')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Mỗi truyện = 1 AI API call (~5-10s)</span>'
                        ))
                        ->visible(fn (callable $get) => $get('ai_story_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_story_content_enabled'))
                        ->columnSpan(1),
                ]),

                // ── Author Composite ────────────────────────────
                Grid::make(3)->schema([
                    Toggle::make('ai_author_content_enabled')
                        ->label('Tạo nội dung & SEO tác giả tự động')
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Tác giả chưa có bio hoặc SEO → tìm kiếm internet + tạo cả nội dung + SEO</span>'
                        ))
                        ->columnSpan(1),

                    Select::make('ai_author_content_frequency')
                        ->label('Tần suất')
                        ->options(ScheduleFrequency::options())
                        ->visible(fn (callable $get) => $get('ai_author_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_author_content_enabled'))
                        ->columnSpan(1),

                    TextInput::make('ai_author_content_batch_size')
                        ->label('Số tác giả / lần chạy')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Mỗi tác giả = 1 AI API call (search internet ~10-15s)</span>'
                        ))
                        ->visible(fn (callable $get) => $get('ai_author_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_author_content_enabled'))
                        ->columnSpan(1),
                ]),

                // ── Category Composite ────────────────────────────
                Grid::make(3)->schema([
                    Toggle::make('ai_category_content_enabled')
                        ->label('Tạo nội dung & SEO thể loại tự động')
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Thể loại chưa có content → tạo mô tả + nội dung chi tiết + SEO</span>'
                        ))
                        ->columnSpan(1),

                    Select::make('ai_category_content_frequency')
                        ->label('Tần suất')
                        ->options(ScheduleFrequency::options())
                        ->visible(fn (callable $get) => $get('ai_category_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_category_content_enabled'))
                        ->columnSpan(1),

                    TextInput::make('ai_category_content_batch_size')
                        ->label('Số thể loại / lần chạy')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Mỗi thể loại = 1 AI API call (~5-10s)</span>'
                        ))
                        ->visible(fn (callable $get) => $get('ai_category_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_category_content_enabled'))
                        ->columnSpan(1),
                ]),
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Content
    // ═══════════════════════════════════════════════════════════════════════

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Lưu cài đặt')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])
                        ->alignment($this->getFormActionsAlignment())
                        ->key('form-actions'),
                ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Save
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save all settings using Setting::set() for consistency.
     */
    public function save(): void
    {
        $data = $this->form->getState();
        $userId = auth()->id();

        // Boolean toggles (ai.*)
        $boolKeys = ['enabled', 'auto_summary', 'auto_author_content', 'auto_category_content', 'content_clean', 'content_moderation', 'cover_generation'];
        foreach ($boolKeys as $key) {
            Setting::set("ai.{$key}", (bool) ($data[$key] ?? false), $userId);
        }

        // String values (ai.*)
        Setting::set('ai.provider', $data['provider'] ?? AiService::DEFAULT_PROVIDER, $userId);
        Setting::set('ai.model', $data['model'] ?? config('ai.providers.' . AiService::DEFAULT_PROVIDER . '.default_model'), $userId);

        // JSON value (clean patterns)
        Setting::set('ai.clean_patterns', $data['clean_patterns'] ?? [], $userId);

        // AI Schedule — Story composite (system.ai_story_*)
        Setting::set('system.ai_story_content_enabled', (bool) ($data['ai_story_content_enabled'] ?? false), $userId);
        Setting::set('system.ai_story_content_frequency', $data['ai_story_content_frequency'] ?? 'hourly', $userId);
        Setting::set('system.ai_story_content_batch_size', (int) ($data['ai_story_content_batch_size'] ?? 3), $userId);

        // AI Schedule — Author composite (system.ai_author_*)
        Setting::set('system.ai_author_content_enabled', (bool) ($data['ai_author_content_enabled'] ?? false), $userId);
        Setting::set('system.ai_author_content_frequency', $data['ai_author_content_frequency'] ?? 'hourly', $userId);
        Setting::set('system.ai_author_content_batch_size', (int) ($data['ai_author_content_batch_size'] ?? 3), $userId);

        // AI Schedule — Category composite (system.ai_category_*)
        Setting::set('system.ai_category_content_enabled', (bool) ($data['ai_category_content_enabled'] ?? false), $userId);
        Setting::set('system.ai_category_content_frequency', $data['ai_category_content_frequency'] ?? 'hourly', $userId);
        Setting::set('system.ai_category_content_batch_size', (int) ($data['ai_category_content_batch_size'] ?? 3), $userId);

        Notification::make()
            ->title('Đã lưu cài đặt')
            ->success()
            ->send();
    }
}
