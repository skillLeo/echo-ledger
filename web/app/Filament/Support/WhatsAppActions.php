<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CaptureSession;
use App\Services\Sharing\WhatsAppMessage;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * "Send to WhatsApp" on the console (since v1.6, P5.5), as on the phone and the desktop: Text only (the view being
 * read), Text and voice (the text in the message, the audio saved to attach) and Link (works for 7 days). The text
 * and the audio leave from the browser: WhatsApp takes a message through wa.me, never a file; the link is made by the
 * page's whatsAppLink() (SendsToWhatsApp), the same share link as Share.
 */
final class WhatsAppActions
{
    /** The words the desktop and the phone use under Text and voice. */
    public const ATTACH_NOTE = 'Attach the saved voice file in WhatsApp';

    /**
     * @param  Closure(): CaptureSession|null  $session  the recording (null: the action's record, in a table)
     * @param  Closure(): (string|null)|null  $view  the Show as view being read (null: the view it opens in)
     * @param  bool  $copyForAi  the page has the player, whose Copy for AI the panel offers too
     */
    public static function send(?Closure $session = null, ?Closure $view = null, string $name = 'whatsapp', bool $copyForAi = false): Action
    {
        $resolve = fn (?CaptureSession $record): CaptureSession => $session !== null ? $session() : $record ?? throw new \LogicException('No recording.');

        return Action::make($name)
            ->label('Send to WhatsApp…')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('gray')
            ->modalHeading('Send to WhatsApp')
            ->modalDescription('Opens WhatsApp, or WhatsApp Web, with the message ready to send to the person you choose.')
            ->modalWidth('md')
            ->visible(fn (?CaptureSession $record): bool => ! $resolve($record)->hasNoAudio())
            ->modalContent(fn (?CaptureSession $record): View => view('filament.library.whatsapp-panel', [...self::panel($resolve($record), $view !== null ? $view() : null), 'copy_ai' => $copyForAi]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * What the panel needs: the text and its wa.me address, the audio's download address, the recording's id for the link.
     *
     * @return array{id: string, text: string|null, url: string|null, cut: bool, audio: string|null, audio_name: string, view: string|null}
     */
    public static function panel(CaptureSession $session, ?string $view): array
    {
        $view = $view !== null ? WhatsAppMessage::readyView($session, $view) : WhatsAppMessage::defaultView($session);
        $text = WhatsAppMessage::transcriptText($session, $view);
        $message = $text !== null ? WhatsAppMessage::url($text) : null;
        $primary = $session->primaryRecording()->first();

        return [
            'id' => $session->id,
            'text' => $text,
            'url' => $message['url'] ?? null,
            'cut' => $message['cut'] ?? false,
            'audio' => $primary?->isReady() === true ? route('admin.recordings.stream', ['recording' => $primary->id, 'download' => 1]) : null,
            'audio_name' => $session->displayTitle(),
            'view' => $view,
        ];
    }
}
