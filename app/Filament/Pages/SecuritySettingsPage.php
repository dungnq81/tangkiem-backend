<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Admin page for managing security settings.
 *
 * Currently supports Cloudflare Turnstile for the backend login form.
 * Located under "Quản lý hệ thống" navigation group.
 */
class SecuritySettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Bảo mật đăng nhập';

    protected static string | UnitEnum | null $navigationGroup = 'Quản lý hệ thống';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Bảo mật đăng nhập';

    protected static ?string $slug = 'security-settings';

    protected string $view = 'filament.pages.security-settings';

    /**
     * Form state.
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // ═══════════════════════════════════════════════════════════════════════
    // Lifecycle
    // ═══════════════════════════════════════════════════════════════════════

    public function mount(): void
    {
        $this->form->fill([
            'turnstile_enabled'    => (bool) Setting::get('security.turnstile_enabled', false),
            'turnstile_site_key'   => Setting::get('security.turnstile_site_key', ''),
            'turnstile_secret_key' => Setting::get('security.turnstile_secret_key', ''),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Form
    // ═══════════════════════════════════════════════════════════════════════

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                self::turnstileSection(),
            ])
            ->statePath('data');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function turnstileSection(): Section
    {
        return Section::make('Cloudflare Turnstile')
            ->description(new HtmlString('
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    <p class="mb-1">Cloudflare Turnstile là giải pháp CAPTCHA thông minh, thay thế reCAPTCHA.</p>
                    <ul class="list-disc pl-4 space-y-0.5">
                        <li>Bảo vệ form đăng nhập backend khỏi bot và brute-force</li>
                        <li>Không cần gửi traffic qua Cloudflare proxy</li>
                        <li><strong>Mức bảo mật</strong> (Managed / Non-Interactive / Invisible) được chọn khi tạo widget trên Cloudflare và gắn liền với Site Key</li>
                        <li>Tạo key tại: <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" class="text-primary-500 hover:underline">Cloudflare Dashboard → Turnstile</a></li>
                    </ul>
                </div>
            '))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema([
                Toggle::make('turnstile_enabled')
                    ->label('Bật Turnstile cho đăng nhập')
                    ->helperText(new HtmlString(
                        '<span class="text-xs">Khi bật, form đăng nhập backend sẽ yêu cầu xác minh Turnstile trước khi đăng nhập.</span>'
                    ))
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('turnstile_site_key')
                    ->label('Site Key')
                    ->placeholder(self::getEnvKeyPlaceholder('key'))
                    ->helperText(new HtmlString(
                        self::getEnvKeyHelperText('key', 'TURNSTILE_SITE_KEY')
                    ))
                    ->required(fn (callable $get) => $get('turnstile_enabled') && empty(config('services.turnstile.key')))
                    ->visible(fn (callable $get) => $get('turnstile_enabled')),

                TextInput::make('turnstile_secret_key')
                    ->label('Secret Key')
                    ->placeholder(self::getEnvKeyPlaceholder('secret'))
                    ->password()
                    ->revealable()
                    ->helperText(new HtmlString(
                        self::getEnvKeyHelperText('secret', 'TURNSTILE_SECRET_KEY')
                    ))
                    ->required(fn (callable $get) => $get('turnstile_enabled') && empty(config('services.turnstile.secret')))
                    ->visible(fn (callable $get) => $get('turnstile_enabled')),
            ])
            ->columns(2);
    }

    /**
     * Get placeholder text indicating .env key status.
     */
    private static function getEnvKeyPlaceholder(string $configKey): string
    {
        $envValue = config("services.turnstile.{$configKey}", '');

        if (!empty($envValue)) {
            $masked = substr((string) $envValue, 0, 8) . '••••••';
            return "Đang dùng .env ({$masked})";
        }

        return '0x4AAAAAAA...';
    }

    /**
     * Get helper text with .env key status info.
     */
    private static function getEnvKeyHelperText(string $configKey, string $envVarName): string
    {
        $envValue = config("services.turnstile.{$configKey}", '');

        if (!empty($envValue)) {
            return '<span class="text-xs text-emerald-600 dark:text-emerald-400">'
                . "✅ Key từ .env ({$envVarName}) đang được sử dụng. "
                . 'Để trống nếu muốn dùng key từ .env, hoặc nhập key mới để ghi đè.'
                . '</span>';
        }

        return '<span class="text-xs">Lấy từ '
            . '<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" class="text-primary-500 hover:underline">'
            . 'Cloudflare Dashboard → Turnstile</a>. '
            . "Hoặc đặt <code>{$envVarName}</code> trong file .env"
            . '</span>';
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
     * Save security settings.
     *
     * When Turnstile is enabled with keys from the DB, we also update
     * config/services.php values at runtime so the package can validate.
     */
    public function save(): void
    {
        $data = $this->form->getState();
        $userId = auth()->id();

        $enabled = (bool) ($data['turnstile_enabled'] ?? false);

        Setting::set('security.turnstile_enabled', $enabled, $userId);

        if ($enabled) {
            Setting::set('security.turnstile_site_key', $data['turnstile_site_key'] ?? '', $userId);
            Setting::set('security.turnstile_secret_key', $data['turnstile_secret_key'] ?? '', $userId);
        }

        Notification::make()
            ->title('Đã lưu cài đặt bảo mật')
            ->body($enabled
                ? 'Turnstile đã được bật. Form đăng nhập sẽ yêu cầu xác minh.'
                : 'Turnstile đã tắt.')
            ->success()
            ->send();
    }
}
