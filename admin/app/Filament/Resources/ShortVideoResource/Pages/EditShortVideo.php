<?php

namespace App\Filament\Resources\ShortVideoResource\Pages;

use App\Filament\Resources\ShortVideoResource;
use App\Services\EfvShortVideoSync;
use DomainException;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditShortVideo extends EditRecord
{
    protected static string $resource = ShortVideoResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $cover = $this->getRecord()->cover;
        if (empty($data['cover']) && str_starts_with((string) $cover, 'https://')) {
            $data['cover'] = $cover;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncEfv')
                ->label('从 EFV 同步并发布')
                ->visible(fn () => ShortVideoResource::canEdit($this->getRecord())
                    && app(EfvShortVideoSync::class)->movieId($this->getRecord()) !== null)
                ->requiresConfirmation()
                ->modalDescription('获取 EFV 转码结果并发布到前台。请先保存需要保留的编辑。')
                ->action(function () {
                    abort_unless(ShortVideoResource::canEdit($this->getRecord()), 403);

                    try {
                        app(EfvShortVideoSync::class)->publish($this->getRecord());
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    $this->refreshFormData(['url', 'cover', 'duration', 'status', 'published_at']);
                    Notification::make()->success()->title('已同步并发布到前台')->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
