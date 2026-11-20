<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devices\Pages;

use App\Exceptions\ApiError;
use App\Filament\Concerns\WatchesPairing;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Support\ConsoleV2;
use App\Models\Device;
use App\Models\User;
use App\Services\PairingService;
use App\Support\Brand;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class ListDevices extends ListRecords
{
    use WatchesPairing;

    protected static string $resource = DeviceResource::class;

    /** Since v2.0 (W-207): in the new frame the title is in the console's language; classic keeps its English. */
    public function getTitle(): string|Htmlable
    {
        return ConsoleV2::shell() ? __('Devices') : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        // Since v2.0 (W-207): in the new frame Sign in a device and its window are in the console's language; classic
        // keeps its English words.
        $v2 = ConsoleV2::shell();
        $t = fn (string $words): string => $v2 ? __($words) : $words;

        return [
            Action::make('pair')
                ->label($t('Sign in a device'))
                ->icon(Heroicon::OutlinedQrCode)
                ->modalHeading($t('Sign in a phone or computer'))
                ->modalDescription($v2
                    ? __('Scan the QR code in the :app app, or type the code. It works once and expires in 10 minutes.', ['app' => Brand::name()])
                    : 'Scan the QR code in the '.Brand::name().' app, or type the code. It works once and expires in 10 minutes.')
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
                    $this->watchPairing($code);

                    return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render($payload), 'url' => rtrim((string) config('app.url'), '/')]];
                })
                // The new frame's copy of the window's body has its words in the console's language (W-207).
                ->schema([ViewField::make('pairing')->view($v2 ? 'filament.v2.pair-device' : 'filament.pair-device')->hiddenLabel()])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel($t('Done'))
                ->modalWidth('md'),
        ];
    }

    /**
     * "Sign in again" (since v1.6): a code bound to one of the person's own devices that is signed out, revoked or
     * expired; the app comes back on the same row.
     *
     * @return array{pairing: array<string, string>}
     */
    public function signInAgainForm(Device $record): array
    {
        /** @var User $user */
        $user = auth()->user();
        try {
            $code = app(PairingService::class)->createBoundCode($user, (string) $record->getKey());
        } catch (ApiError|ValidationException $e) {
            return ['pairing' => ['error' => $e->getMessage()]];
        }
        $this->watchPairing($code);

        return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render(app(PairingService::class)->qrPayload($code)), 'url' => rtrim((string) config('app.url'), '/')]];
    }

    /** A list page is the top of its section: no "Sessions > List" trail (C3). */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
