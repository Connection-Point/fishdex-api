<?php

namespace App\Filament\Pages;

use App\Domain\Admin\Models\Admin;
use App\Domain\Identification\Services\AiCapacity;
use App\Domain\Settings\Services\SettingsService;
use App\Domain\Shared\Enums\AdminRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Runtime-tunable settings (app_settings overrides config/fishdex.php).
 * Super admins only. Every save is written to the admin audit log.
 */
class AppSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'AI & app settings';

    /** form field => settings key */
    private const FIELDS = [
        'kill_switch' => 'ai.kill_switch',
        'ai_tier' => 'ai.tier',
        'free_rpm' => 'ai.free_tier.rpm',
        'free_rpd' => 'ai.free_tier.rpd',
        'free_usable_ratio' => 'ai.free_tier.usable_ratio',
        'paid_daily_budget_usd' => 'ai.paid.daily_budget_usd',
        'pass2_free' => 'ai.pass2_enabled.free',
        'pass2_paid' => 'ai.pass2_enabled.paid',
        'confidence_high' => 'confidence.high',
        'confidence_high_margin' => 'confidence.high_min_margin',
        'confidence_medium' => 'confidence.medium',
        'quota_free_daily' => 'quotas.daily.free',
        'quota_premium_daily' => 'quotas.daily.premium',
        'quota_new_account_daily' => 'quotas.new_account_daily',
        'min_app_version' => 'min_supported_app_version',
    ];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->hasRole(AdminRole::SuperAdmin);
    }

    public function mount(SettingsService $settings): void
    {
        $values = [];
        foreach (self::FIELDS as $field => $key) {
            $values[$field] = $settings->get($key);
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $capacity = app(AiCapacity::class);

        return $schema->statePath('data')->components([
            Section::make('AI identification')
                ->description(fn () => 'Today: '.$capacity->usedToday().' AI requests used'
                    .($capacity->dailyLimit() !== null ? ' of '.$capacity->dailyLimit().' (resets '.$capacity->nextDailyReset()->setTimezone('Asia/Kuala_Lumpur')->format('D H:i').' MYT)' : ''))
                ->columns(3)
                ->schema([
                    Toggle::make('kill_switch')->label('Pause all scanning (kill switch)')->columnSpanFull()
                        ->helperText('New scans get "temporarily unavailable"; queued scans wait.'),
                    Select::make('ai_tier')->label('AI tier')->options(['free' => 'Free (closed beta)', 'paid' => 'Paid (production)'])->required(),
                    TextInput::make('free_rpm')->label('Free tier: requests / minute')->numeric()->minValue(1)->helperText('From AI Studio rate-limit page'),
                    TextInput::make('free_rpd')->label('Free tier: requests / day')->numeric()->minValue(1)->helperText('From AI Studio rate-limit page'),
                    TextInput::make('free_usable_ratio')->label('Share of daily limit for users')->numeric()->minValue(0.1)->maxValue(1)->step(0.05),
                    TextInput::make('paid_daily_budget_usd')->label('Paid: daily budget (USD)')->numeric()->minValue(0),
                    Toggle::make('pass2_free')->label('Second AI pass on free tier'),
                    Toggle::make('pass2_paid')->label('Second AI pass on paid tier'),
                ]),
            Section::make('Confidence bands')->columns(3)->schema([
                TextInput::make('confidence_high')->label('High ≥')->numeric()->minValue(0)->maxValue(1)->step(0.01),
                TextInput::make('confidence_high_margin')->label('High needs margin ≥')->numeric()->minValue(0)->maxValue(1)->step(0.01),
                TextInput::make('confidence_medium')->label('Medium ≥')->numeric()->minValue(0)->maxValue(1)->step(0.01),
            ]),
            Section::make('Scan quotas (per user per day)')->columns(3)->schema([
                TextInput::make('quota_free_daily')->label('Free plan')->numeric()->minValue(0),
                TextInput::make('quota_premium_daily')->label('Premium plan')->numeric()->minValue(0),
                TextInput::make('quota_new_account_daily')->label('First 24 h of a new account')->numeric()->minValue(0),
            ]),
            Section::make('App')->schema([
                TextInput::make('min_app_version')->label('Minimum supported app version')->regex('/^\d+\.\d+\.\d+$/'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save settings')->submit('save')])]),
        ]);
    }

    public function save(SettingsService $settings): void
    {
        $state = $this->form->getState();
        $casts = ['kill_switch' => 'bool', 'pass2_free' => 'bool', 'pass2_paid' => 'bool', 'ai_tier' => 'string', 'min_app_version' => 'string'];

        foreach (self::FIELDS as $field => $key) {
            $value = $state[$field] ?? null;
            $value = match ($casts[$field] ?? 'number') {
                'bool' => (bool) $value,
                'string' => (string) $value,
                default => is_numeric($value) ? $value + 0 : $value,
            };
            $settings->set($key, $value, auth('admin')->id());
        }

        Notification::make()->title('Settings saved')->success()->send();
    }
}
