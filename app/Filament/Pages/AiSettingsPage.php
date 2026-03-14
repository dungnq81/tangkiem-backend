<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ScheduleFrequency;
use App\Models\Setting;
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
 * All settings stored in `settings` table (group: 'ai').
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
                    // Schedule ALL cache operations to run AFTER the HTTP
                    // response is sent. Running Artisan cache commands during
                    // a Livewire request destroys compiled views, Filament
                    // component caches, and app cache mid-render — corrupting
                    // Livewire's component snapshot ("children" key lost),
                    // and causing $errors undefined on re-compiled views.
                    app()->terminating(function (): void {
                        // Phase 0: Reset OPcache (critical for FTP deploys)
                        // OPcache keeps stale bytecode in memory even after
                        // files are replaced on disk via FTP/SFTP.
                        if (function_exists('opcache_reset')) {
                            opcache_reset();
                        }

                        // Phase 1: Clear
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
                                // Command not available — skip
                            }
                        }

                        Artisan::call('cache:clear');

                        // Phase 2: Rebuild
                        Artisan::call('optimize');

                        $optionalRebuilds = [
                            'filament:optimize',
                            'icons:cache',
                        ];
                        foreach ($optionalRebuilds as $cmd) {
                            try {
                                Artisan::call($cmd);
                            } catch (\Throwable) {
                                // Command not available — skip
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
            'provider'           => Setting::get('ai.provider', 'gemini'),
            'model'              => Setting::get('ai.model', config('ai.providers.gemini.default_model', 'gemini-2.5-flash-lite')),

            // AI Features
            'auto_categorize'    => (bool) Setting::get('ai.auto_categorize', false),
            'auto_summary'       => (bool) Setting::get('ai.auto_summary', false),
            'content_clean'      => (bool) Setting::get('ai.content_clean', false),
            'content_moderation' => (bool) Setting::get('ai.content_moderation', false),
            'cover_generation'   => (bool) Setting::get('ai.cover_generation', false),

            // AI Schedule (Auto content & SEO)
            'ai_content_enabled'    => (bool) Setting::get('system.ai_content_enabled', false),
            'ai_content_frequency'  => Setting::get('system.ai_content_frequency', 'hourly'),
            'ai_content_batch_size' => (int) Setting::get('system.ai_content_batch_size', 3),
            'ai_seo_enabled'        => (bool) Setting::get('system.ai_seo_enabled', false),
            'ai_seo_frequency'      => Setting::get('system.ai_seo_frequency', 'hourly'),
            'ai_seo_batch_size'     => (int) Setting::get('system.ai_seo_batch_size', 5),

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
                        // Reset model to provider's default when switching providers
                        $default = config("ai.providers.{$state}.default_model");
                        $set('model', $default);
                    }),

                Select::make('model')
                    ->label('Model mặc định')
                    ->options(function (callable $get): array {
                        $provider = $get('provider') ?: 'gemini';

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
                    Bật/tắt từng tính năng riêng lẻ. Chỉ hoạt động khi <strong>AI tổng quan</strong> đã bật.
                </div>
            '))
            ->schema([
                Toggle::make('auto_categorize')
                    ->label('AI Phân loại tự động')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Gợi ý thể loại, tags, loại truyện, nguồn gốc khi chỉnh sửa truyện.</span>
                    ')),

                Toggle::make('auto_summary')
                    ->label('AI Tạo mô tả')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tạo mô tả truyện tự động từ nội dung các chương đầu.</span>
                    ')),

                Toggle::make('content_clean')
                    ->label('AI Dọn dẹp nội dung')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Dọn dẹp nội dung chương: loại bỏ quảng cáo, watermark, text rác.</span>
                    ')),

                Toggle::make('content_moderation')
                    ->label('AI Kiểm duyệt bình luận')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tự động ẩn bình luận spam, toxic, NSFW khi người dùng đăng.</span>
                    ')),

                Toggle::make('cover_generation')
                    ->label('AI Tạo ảnh bìa')
                    ->helperText(new HtmlString('
                        <span class="text-xs">Tạo ảnh bìa truyện bằng Gemini Imagen.</span>
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
                    <p class="mb-1">Tự động chạy AI tạo nội dung và SEO cho truyện theo lịch.</p>
                    <ul class="list-disc pl-4 space-y-0.5">
                        <li><strong>Tạo nội dung:</strong> Tự động tạo nội dung giới thiệu cho truyện chưa có content (từ chapters hoặc tìm kiếm internet)</li>
                        <li><strong>Tạo SEO:</strong> Tự động tạo meta_title, meta_description, meta_keywords cho truyện đã có content nhưng chưa có SEO</li>
                        <li>Hoạt động với cả <strong>server cron</strong> và <strong>web cron</strong></li>
                        <li>Yêu cầu AI phải được bật ở mục trên (enabled + auto_summary)</li>
                        <li>Batch size nhỏ để tránh vượt giới hạn API (mỗi truyện = 1 API call)</li>
                    </ul>
                </div>
            '))
            ->schema([
                // ── AI Content Generation ────────────────────────────
                Grid::make(3)->schema([
                    Toggle::make('ai_content_enabled')
                        ->label('Bật tạo nội dung tự động')
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Tự động tạo nội dung cho truyện chưa có content</span>'
                        ))
                        ->columnSpan(1),

                    Select::make('ai_content_frequency')
                        ->label('Tần suất')
                        ->options(ScheduleFrequency::options())
                        ->visible(fn (callable $get) => $get('ai_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_content_enabled'))
                        ->columnSpan(1),

                    TextInput::make('ai_content_batch_size')
                        ->label('Số truyện / lần chạy')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Mỗi truyện = 1 AI API call (~5-10s)</span>'
                        ))
                        ->visible(fn (callable $get) => $get('ai_content_enabled'))
                        ->required(fn (callable $get) => $get('ai_content_enabled'))
                        ->columnSpan(1),
                ]),

                // ── AI SEO Generation ────────────────────────────────
                Grid::make(3)->schema([
                    Toggle::make('ai_seo_enabled')
                        ->label('Bật tạo SEO tự động')
                        ->helperText(new HtmlString(
                            '<span class="text-xs">Tự động tạo SEO cho truyện đã có content nhưng chưa có meta</span>'
                        ))
                        ->columnSpan(1),

                    Select::make('ai_seo_frequency')
                        ->label('Tần suất')
                        ->options(ScheduleFrequency::options())
                        ->visible(fn (callable $get) => $get('ai_seo_enabled'))
                        ->required(fn (callable $get) => $get('ai_seo_enabled'))
                        ->columnSpan(1),

                    TextInput::make('ai_seo_batch_size')
                        ->label('Số truyện / lần chạy')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->helperText(new HtmlString(
                            '<span class="text-xs">SEO nhanh hơn content, có thể batch lớn hơn</span>'
                        ))
                        ->visible(fn (callable $get) => $get('ai_seo_enabled'))
                        ->required(fn (callable $get) => $get('ai_seo_enabled'))
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
        $boolKeys = ['enabled', 'auto_categorize', 'auto_summary', 'content_clean', 'content_moderation', 'cover_generation'];
        foreach ($boolKeys as $key) {
            Setting::set("ai.{$key}", (bool) ($data[$key] ?? false), $userId);
        }

        // String values (ai.*)
        Setting::set('ai.provider', $data['provider'] ?? 'gemini', $userId);
        Setting::set('ai.model', $data['model'] ?? config('ai.providers.gemini.default_model', 'gemini-2.5-flash-lite'), $userId);

        // JSON value (clean patterns)
        Setting::set('ai.clean_patterns', $data['clean_patterns'] ?? [], $userId);

        // AI Schedule — Content (system.ai_*)
        Setting::set('system.ai_content_enabled', (bool) ($data['ai_content_enabled'] ?? false), $userId);
        Setting::set('system.ai_content_frequency', $data['ai_content_frequency'] ?? 'hourly', $userId);
        Setting::set('system.ai_content_batch_size', (int) ($data['ai_content_batch_size'] ?? 3), $userId);

        // AI Schedule — SEO (system.ai_*)
        Setting::set('system.ai_seo_enabled', (bool) ($data['ai_seo_enabled'] ?? false), $userId);
        Setting::set('system.ai_seo_frequency', $data['ai_seo_frequency'] ?? 'hourly', $userId);
        Setting::set('system.ai_seo_batch_size', (int) ($data['ai_seo_batch_size'] ?? 5), $userId);

        Notification::make()
            ->title('Đã lưu cài đặt')
            ->success()
            ->send();
    }
}
