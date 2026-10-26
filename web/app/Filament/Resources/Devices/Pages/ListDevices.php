<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devices\Pages;

use App\Exceptions\ApiError;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\User;
use App\Services\PairingService;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pair')
                ->label('Pair a device')
                ->icon(Heroicon::OutlinedQrCode)
                ->modalHeading('Pair a phone or desktop')
                ->modalDescription('Scan the QR code in the EchoLedger app, or type the code. It works once and expires in 10 minutes.')
                // fillForm runs once on mount, so the code is generated exactly once per modal.
                ->fillForm(function (PairingService $pairing): array {
                    /** @var User $user */
                    $user = auth()->user();
                    try {
                        $code = $pairing->createCode($user);
                    } catch (ApiError $e) {
                        return ['pairing' => ['error' => $e->getMessage()]]; // plan device limit (B20)
                    }
                    $payload = $pairing->qrPayload($code);

                    return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render($payload), 'url' => rtrim((string) config('app.url'), '/')]];
                })
                ->schema([ViewField::make('pairing')->view('filament.pair-device')->hiddenLabel()])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Done')
                ->modalWidth('md'),
        ];
    }

    /** A list page is the top of its section: no "Sessions > List" trail (C3). */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
