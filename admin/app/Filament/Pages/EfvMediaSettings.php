<?php

namespace App\Filament\Pages;

use App\Services\EfvMediaDomains;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use RuntimeException;

class EfvMediaSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = '系统';

    protected static ?string $title = 'EFV 媒体域名';

    protected static string $view = 'filament.pages.efv-media-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin && $admin->is_active && $admin->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(app(EfvMediaDomains::class)->read());
    }

    public function form(Form $form): Form
    {
        $originRule = fn () => function (string $attribute, mixed $value, Closure $fail): void {
            if (EfvMediaDomains::origin($value) === null) {
                $fail('请输入 HTTPS 域名，去掉路径、参数和账号信息。');
            }
        };

        return $form->schema([
            TextInput::make('image_origin')
                ->label('封面和截图域名')
                ->placeholder('https://tupian.5cpic1.top')
                ->required()->maxLength(255)->rule($originRule),
            TextInput::make('m3u8_origin')
                ->label('M3U8 播放域名')
                ->placeholder('https://m3u8.5cvod1.top')
                ->required()->maxLength(255)->rule($originRule),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $config = app(EfvMediaDomains::class);
        try {
            $config->save($this->form->getState());
        } catch (RuntimeException $error) {
            Notification::make()->danger()->title($error->getMessage())->send();

            return;
        }

        $this->form->fill($config->read());
        Notification::make()->success()->title('媒体域名已保存')->send();
    }
}
