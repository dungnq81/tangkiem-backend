<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeSources\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ScrapeSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::basicInfoSection(),
            self::aiConfigSection(),
            self::contentCleaningSection(),
            self::advancedSection(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function basicInfoSection(): Section
    {
        return Section::make('Thông tin cơ bản')
            ->schema([
                Grid::make(2)->schema([
                    self::nameInput(),
                    self::baseUrlInput(),
                    self::renderTypeToggle(),
                    self::extractionMethodSelect(),
                    self::delayInput(),
                    self::concurrencyInput(),
                    self::maxRetriesInput(),
                    self::activeToggle(),
                    self::cleanupAfterDaysInput(),
                ]),
            ])
			->collapsed(false);
    }

    private static function aiConfigSection(): Section
    {
        return Section::make('🤖 Cấu hình AI')
            ->description('Cấu hình AI provider và prompt mẫu cho nguồn này.')
            ->schema([
                Grid::make(2)->schema([
                    self::aiProviderSelect(),
                    self::aiModelSelect(),
                ]),
                self::aiPromptTemplateTextarea(),
            ])
            ->visible(fn (callable $get) => $get('extraction_method') === 'ai_prompt')
            ->collapsed(false);
    }

    private static function contentCleaningSection(): Section
    {
        return Section::make('🧹 Làm sạch nội dung chương')
            ->description('Áp dụng cho TẤT CẢ tác vụ thu thập chương từ nguồn này. Giá trị ở đây sẽ được ghép chung với giá trị riêng từng tác vụ.')
            ->schema([
                self::globalRemoveSelectorsInput(),
                self::globalRemoveTextPatternsInput(),
            ])
            ->visible(fn (callable $get) => $get('extraction_method') === 'css_selector')
            ->collapsed(false);
    }

    private static function advancedSection(): Section
    {
        return Section::make('Cấu hình nâng cao')
            ->schema([
                self::headersInput(),
                self::notesTextarea(),
            ])
			->collapsed(false);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label('Tên nguồn')
            ->placeholder('Tang Thu Vien')
            ->required()
            ->maxLength(255);
    }

    private static function baseUrlInput(): TextInput
    {
        return TextInput::make('base_url')
            ->label('URL gốc (domain)')
            ->placeholder('https://truyen.tangthuvien.vn')
            ->required()
            ->url()
            ->maxLength(500)
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li>Domain gốc của website, dùng để ghép URL tương đối</li>
                    <li>VD: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">/tac-gia/abc</code> → domain + <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">/tac-gia/abc</code></li>
                    <li>Đây <strong>không phải</strong> URL thu thập — URL cụ thể nhập ở từng tác vụ</li>
                </ul>
            '));
    }

    private static function renderTypeToggle(): Toggle
    {
        return Toggle::make('is_spa')
            ->label('Dùng trình duyệt ảo')
            ->onColor('warning')
            ->offColor('gray')
            ->default(false)
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li><strong>Tắt</strong> (mặc định) — dùng cURL, nhanh, phù hợp hầu hết web</li>
                    <li><strong>Bật</strong> — dùng Headless Chrome, cho web render bằng JavaScript (Vue/React)</li>
                </ul>
            '));
    }

    private static function extractionMethodSelect(): Select
    {
        return Select::make('extraction_method')
            ->label('Phương thức trích xuất')
            ->options([
                'ai_prompt'    => '🤖 AI + Prompt (tự động)',
                'css_selector' => '🎯 CSS Selectors (thủ công)',
            ])
            ->default('ai_prompt')
            ->required()
            ->live()
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li><strong>AI</strong> — chỉ cần viết prompt, AI tự trích xuất dữ liệu</li>
                    <li><strong>CSS</strong> — cấu hình selector thủ công cho mỗi tác vụ</li>
                </ul>
            '));
    }

    private static function aiProviderSelect(): Select
    {
        return Select::make('ai_provider')
            ->label('AI Provider')
            ->options(function (): array {
                $options = ['' => '🔄 Mặc định (theo Cài đặt AI)'];
                foreach (config('ai.providers', []) as $key => $cfg) {
                    $options[$key] = ucfirst($key);
                }

                return $options;
            })
            ->default('')
            ->live()
            ->afterStateUpdated(function ($state, callable $set): void {
                // Reset model when provider changes
                $set('ai_model', null);
            })
            ->helperText('Để trống = dùng provider từ Cài đặt AI toàn cục.');
    }

    private static function aiModelSelect(): Select
    {
        return Select::make('ai_model')
            ->label('Model')
            ->options(function (callable $get): array {
                $provider = $get('ai_provider');

                if (empty($provider)) {
                    // Show all models from all providers
                    $allModels = ['' => '🔄 Mặc định (theo Cài đặt AI)'];
                    foreach (config('ai.providers', []) as $providerKey => $providerConfig) {
                        foreach ($providerConfig['models'] ?? [] as $modelKey => $modelLabel) {
                            $allModels[$modelKey] = $modelLabel;
                        }
                    }

                    return $allModels;
                }

                return array_merge(
                    ['' => '🔄 Mặc định (theo Cài đặt AI)'],
                    config("ai.providers.{$provider}.models", [])
                );
            })
            ->default('')
            ->native(false)
            ->searchable()
            ->helperText('Để trống = dùng model từ Cài đặt AI toàn cục.');
    }

    private static function aiPromptTemplateTextarea(): Textarea
    {
        return Textarea::make('ai_prompt_template')
            ->label('Prompt mẫu (tùy chọn)')
            ->rows(6)
            ->placeholder('VD: Trích xuất danh sách truyện từ HTML. Với mỗi truyện, lấy: title, url, author, categories...')
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li>Prompt dự phòng — chỉ dùng khi tác vụ không có prompt riêng</li>
                    <li>Mỗi loại dữ liệu (truyện, tác giả, danh mục…) cần prompt khác nhau</li>
                    <li>💡 Nên viết prompt trực tiếp ở từng tác vụ sẽ chính xác hơn</li>
                </ul>
            '));
    }

    private static function delayInput(): TextInput
    {
        return TextInput::make('delay_ms')
            ->label('Delay (ms)')
            ->numeric()
            ->default(2000)
            ->minValue(500)
            ->maxValue(30000)
            ->helperText('Delay giữa các batch request (tránh bị block)');
    }

    private static function concurrencyInput(): TextInput
    {
        return TextInput::make('max_concurrency')
            ->label('Fetch song song')
            ->numeric()
            ->default(3)
            ->minValue(1)
            ->maxValue(10)
            ->helperText('Số request đồng thời khi fetch nội dung (1 = tuần tự)');
    }

    private static function maxRetriesInput(): TextInput
    {
        return TextInput::make('max_retries')
            ->label('Số lần retry')
            ->numeric()
            ->default(3)
            ->minValue(0)
            ->maxValue(10);
    }

    private static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Hoạt động')
            ->default(true);
    }

    private static function cleanupAfterDaysInput(): TextInput
    {
        return TextInput::make('cleanup_after_days')
            ->label('Tự xóa kết quả sau (ngày)')
            ->numeric()
            ->default(0)
            ->minValue(0)
            ->maxValue(365)
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li>Số ngày giữ lại kết quả thu thập</li>
                    <li><strong>0</strong> = không tự xóa</li>
                    <li>VD: <strong>30</strong> = xóa items cũ hơn 30 ngày</li>
                </ul>
            '));
    }

    private static function headersInput(): KeyValue
    {
        return KeyValue::make('default_headers')
            ->label('HTTP Headers')
            ->keyLabel('Header')
            ->valueLabel('Giá trị')
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li>Headers gửi kèm mỗi request tới website nguồn</li>
                    <li>VD: <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">User-Agent</code>, <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">Cookie</code>, <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">Referer</code></li>
                </ul>
            '));
    }

    private static function notesTextarea(): Textarea
    {
        return Textarea::make('notes')
            ->label('Ghi chú')
            ->rows(3);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Content Cleaning Components (Global)
    // ═══════════════════════════════════════════════════════════════════════

    private static function globalRemoveSelectorsInput(): Textarea
    {
        return Textarea::make('remove_selectors')
            ->label('Selectors cần loại bỏ (chung)')
            ->placeholder(".ads\n.chapter-nav\nscript\n.social-share")
            ->rows(4)
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li>Mỗi dòng 1 CSS selector — xóa khỏi trang trước khi lấy nội dung chương</li>
                    <li>Áp dụng cho <strong>tất cả tác vụ</strong> sử dụng nguồn này</li>
                    <li>Giá trị này sẽ được <strong>ghép chung</strong> với phần cấu hình riêng ở từng tác vụ</li>
                </ul>
            '));
    }

    private static function globalRemoveTextPatternsInput(): Textarea
    {
        return Textarea::make('remove_text_patterns')
            ->label('Chuỗi text cần loại bỏ (chung)')
            ->placeholder("Nguồn: tangthuvien.vn\nChương trình ủng hộ thương hiệu Việt...")
            ->rows(4)
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li>Mỗi dòng 1 chuỗi text — xóa khỏi nội dung sau khi extract</li>
                    <li>Áp dụng cho <strong>tất cả tác vụ</strong> sử dụng nguồn này</li>
                    <li>Giá trị này sẽ được <strong>ghép chung</strong> với phần cấu hình riêng ở từng tác vụ</li>
                </ul>
            '));
    }
}
