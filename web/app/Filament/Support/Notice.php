<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Notifications\View\Components\NotificationComponent;
use Filament\Notifications\View\Components\NotificationComponent\IconComponent;
use Filament\Notifications\View\NotificationsIconAlias;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
use Illuminate\Support\Js;

use function Filament\Support\generate_icon_html;

/**
 * Every toast of the console and the admin panel (since v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): Filament's
 * notification, bound in its place (AppServiceProvider), so every Notification::make() in the code is one of these.
 *
 * - A target (`url()`): the whole card opens it, and the toast closes as it goes. A notice about nothing in
 *   particular has none and is not clickable. Only an address on this server is kept.
 * - × "Dismiss" on every toast; a timer (6 s, an error 10 s) with a thin bar along its bottom edge that stops while
 *   the pointer is on it, a finger is on it or it has the keyboard; swipe left or right to close; Esc closes the
 *   newest (the behaviour is window.elToast, in filament.shell.notices, over Filament's own notificationComponent).
 * - In the bell's list (inline) the card opens its notification's target and marks it read; × or a swipe takes it
 *   off the list.
 */
class Notice extends Notification
{
    protected ?string $url = null;

    /** Where the notice goes when it is clicked: the recording, the setting, the page that fixes it. */
    public function url(?string $url): static
    {
        $this->url = self::internal($url);

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /** An error stays 10 s, a message 6 s (Filament's default); a persistent one has no timer. */
    public function getDuration(): int|string
    {
        $duration = parent::getDuration();

        return $duration === 6000 && $this->getStatus() === 'danger' ? 10000 : $duration;
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'url' => $this->url];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        $notice = parent::fromArray($data);
        $notice->url(is_string($data['url'] ?? null) ? $data['url'] : null);

        return $notice;
    }

    /** Only an address on this server (a path, or this app's own URL): a stored notice never leads anywhere else. */
    public static function internal(?string $url): ?string
    {
        if ($url === null || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\s\\\\]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }
        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' && ($url === $base || str_starts_with($url, $base.'/') || str_starts_with($url, $base.'?')) ? $url : null;
    }

    public function toEmbeddedHtml(): string
    {
        $status = $this->getStatus();
        $title = $this->getTitle();
        $hasTitle = filled($title);
        $date = $this->getDate();
        $hasDate = filled($date);
        $body = $this->getBody();
        $hasBody = filled($body);
        $url = $this->url;
        $inline = $this->isInline;
        $duration = $this->getDuration();
        $timer = ! $inline && is_int($duration) && $duration > 0;

        $attributes = (new FilamentComponentAttributeBag)
            ->merge([
                // Errors are announced at once (`alert`), the rest politely through the notifications' `status` region;
                // the bell's list never replays its stored errors.
                'role' => ($status === 'danger' && ! $inline) ? 'alert' : null,
                'wire:key' => "{$this->getId()}.notifications.{$this->getId()}",
                'x-on:close-notification.window' => 'if ($event.detail.id == '.Js::from($this->getId()).') close()',
                'data-el-toast' => $inline ? 'inline' : 'toast',
                'data-el-target' => $url,
            ])
            ->color(NotificationComponent::class, $this->getColor() ?? 'gray')
            ->class([
                'fi-no-notification',
                'el-toast',
                'el-has-target' => $url !== null,
                'el-has-timer' => $timer,
                'fi-inline' => $inline,
                "fi-status-{$status}" => $status,
            ]);

        $titleHtml = $hasTitle ? (string) str($title)->sanitizeHtml() : '';
        $bodyHtml = $hasBody ? (string) str($body)->sanitizeHtml() : '';
        $link = fn (string $inner): string => '<a href="'.e((string) $url).'" class="el-toast-link" draggable="false" x-on:click="opened($event)">'.$inner.'</a>';

        ob_start(); ?>

        <div
            x-data="(window.elToast || ((n) => n))(notificationComponent({ notification: <?= Js::from(['id' => $this->getId(), 'duration' => $duration]) ?> }), <?= Js::from(['inline' => $inline, 'url' => $url]) ?>)"
            x-transition:enter-start="fi-transition-enter-start"
            x-transition:enter-end="fi-transition-enter-end"
            x-transition:leave-start="fi-transition-leave-start"
            x-transition:leave-end="fi-transition-leave-end"
            <?php if (! $inline) { ?>
                x-on:mouseenter="pauseDuration('hover')"
                x-on:mouseleave="resumeDuration('hover')"
                x-on:focusin="handleFocusIn($event)"
                x-on:focusout="handleFocusOut($event)"
                x-on:keydown.escape="handleEscape($event)"
            <?php } ?>
            <?= $attributes ?>
        >
            <?= generate_icon_html(
                $this->getIcon(),
                attributes: (new FilamentComponentAttributeBag)->color(IconComponent::class, $this->getIconColor())->class(['fi-no-notification-icon']),
                size: $this->getIconSize(),
            )?->toHtml() ?>

            <div class="fi-no-notification-main">
                <?php if ($hasTitle || $hasDate || $hasBody) { ?>
                    <div class="fi-no-notification-text">
                        <?php if ($hasTitle) { ?>
                            <h3 class="fi-no-notification-title">
                                <?= $url !== null ? $link($titleHtml) : $titleHtml ?>
                            </h3>
                        <?php } ?>

                        <?php if ($hasDate) { ?>
                            <time class="fi-no-notification-date">
                                <?= e($date) ?>
                            </time>
                        <?php } ?>

                        <?php if ($hasBody) { ?>
                            <div class="fi-no-notification-body">
                                <?= $url !== null && ! $hasTitle ? $link($bodyHtml) : $bodyHtml ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php if ($actions = $this->getActions()) { ?>
                    <div class="fi-ac fi-no-notification-actions">
                        <?php foreach ($actions as $action) { ?>
                            <?= $action->toHtml() ?>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>

            <button
                type="button"
                x-on:click="<?= $inline ? 'close' : 'dismiss()' ?>"
                aria-label="Dismiss"
                title="Dismiss"
                class="fi-icon-btn fi-no-notification-close-btn el-toast-close"
            >
                <?= generate_icon_html(Heroicon::XMark, alias: NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON)->toHtml() ?>
            </button>

            <?php if ($timer) { ?>
                <span class="el-toast-timer" aria-hidden="true">
                    <span class="el-toast-timer-bar" style="animation-duration: <?= (int) $duration ?>ms" x-bind:class="{ 'el-paused': isHovered || isFocusedWithin || dragging || touching }"></span>
                </span>
            <?php } ?>
        </div>

        <?php return (string) ob_get_clean();
    }

    /** The bell's Open button stays for the apps' sake, but the card itself opens the notification. */
    public static function withoutOpenAction(self $notice): self
    {
        return $notice->actions(array_values(array_filter($notice->getActions(), fn ($a): bool => ! ($a instanceof Action && $a->getName() === 'open'))));
    }
}
