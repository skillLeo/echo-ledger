<?php

declare(strict_types=1);

use Filament\Actions\Action;

/* A dialog's main button names what it does (found in a real browser: Invite's dialog said "Submit"). */

it('names the button after the action, without a trailing ellipsis', function () {
    expect(Action::make('addMember')->label('Invite')->schema([])->getModalSubmitActionLabel())->toBe('Invite')
        ->and(Action::make('as')->label('Re-transcribe as…')->schema([])->getModalSubmitActionLabel())->toBe('Re-transcribe as')
        ->and(Action::make('delete')->label('Delete')->requiresConfirmation()->getModalSubmitActionLabel())->toBe('Delete');
});

it('keeps a button label an action chose itself', function () {
    expect(Action::make('signIn')->label('Sign in a device')->modalSubmitActionLabel('Done')->getModalSubmitActionLabel())->toBe('Done');
});
