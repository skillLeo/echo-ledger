<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Stt\LanguageChoice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The people at a client (since v1.2): a recording whose caller has one of these names or phone numbers is filed under
 * the client, and gets the contact's language when it has none.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contacts';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)
                ->helperText('As it shows on calls: the WhatsApp name or the name in your phone.'),
            TextInput::make('phone')->tel()->maxLength(40)->helperText('Any format: +92 300 1234567 and 0300-1234567 match.'),
            TextInput::make('email')->email()->maxLength(190)->helperText('Matches calendar invitations.'),
            Select::make('language')->label('Language')->options(LanguageChoice::selectOptions())->placeholder("The client's language")
                ->dehydrateStateUsing(fn (?string $state): ?string => LanguageChoice::normalize($state)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('language')->formatStateUsing(fn (?string $state): string => $state === null ? '—' : LanguageChoice::label($state))->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Add contact')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
