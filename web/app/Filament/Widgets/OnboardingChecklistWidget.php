<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\ApiCredentials;
use App\Filament\Pages\Security;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\User;
use App\Services\OnboardingChecklist;
use Filament\Widgets\Widget;

/** The setup checklist on the dashboard (plan B19); see {@see OnboardingChecklist}. */
class OnboardingChecklistWidget extends Widget
{
    protected static ?int $sort = -10;

    /** Rendered with the page: a card that pops in after the dashboard has loaded would shift everything below it. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.onboarding-checklist';

    public static function canView(): bool
    {
        $user = auth()->user();

        return app(OnboardingChecklist::class)->visibleTo($user instanceof User ? $user : null);
    }

    public function dismiss(): void
    {
        app(OnboardingChecklist::class)->dismiss();
    }

    /**
     * The steps for the view: title, hint, link and whether each is done.
     *
     * @return list<array{id: string, title: string, hint: string, link: string, url: string, done: bool}>
     */
    public function steps(): array
    {
        $user = auth()->user();
        $status = $user instanceof User ? app(OnboardingChecklist::class)->status($user) : [];
        $copy = [
            'two_factor' => ['Turn on two-factor sign-in', 'Protects the console with an authenticator app or a passkey.', 'Open Security', Security::getUrl()],
            'provider_key' => ['Add a provider key', 'Needed for live captions and transcripts.', 'Open API Credentials', ApiCredentials::getUrl()],
            'cert_pins' => ['Set certificate pins', 'Lets phones and computers check that they talk to this server.', 'Open Settings', Settings::getUrl()],
            'first_device' => ['Pair your first device', 'Scan the code from the phone or desktop app.', 'Open Devices', DeviceResource::getUrl()],
            'retention' => ['Choose how long to keep recordings', 'Pick a retention time and save it.', 'Open Settings', Settings::getUrl()],
        ];

        return array_map(
            fn (string $id): array => [
                'id' => $id,
                'title' => $copy[$id][0],
                'hint' => $copy[$id][1],
                'link' => $copy[$id][2],
                'url' => $copy[$id][3],
                'done' => $status[$id] ?? false,
            ],
            OnboardingChecklist::STEPS,
        );
    }
}
