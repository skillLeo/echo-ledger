<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Models\Client;
use App\Stt\KeytermPolicy;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Per-client vocabulary (names, products, jargon). Sanitized with the same policy the providers use. */
class KeytermsRelationManager extends RelationManager
{
    protected static string $relationship = 'keyterms';

    protected static ?string $title = 'Keyterms';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('term')->required()->maxLength(KeytermPolicy::MAX_CHARS)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('client_id', $this->getOwnerRecord()->getKey()))
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (($message = KeytermPolicy::validate((string) $value)) !== null) {
                        $fail($message);
                    }
                })
                ->dehydrateStateUsing(fn (?string $state): string => KeytermPolicy::sanitize([$state])[0] ?? '')
                ->helperText('Names, products, places or jargon (≤ 50 chars, ≤ 5 words). Live captions use the first 50–100.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('term')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('term')->searchable()->weight('medium'),
                TextColumn::make('created_at')->since()->label('Added'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add keyterm'),
                Action::make('bulkAdd')
                    ->label('Paste list')
                    ->schema([Textarea::make('terms')->rows(8)->required()->helperText('One keyterm per line or comma-separated.')])
                    ->action(function (array $data): void {
                        /** @var Client $client */
                        $client = $this->getOwnerRecord();
                        $existing = array_map('mb_strtolower', $client->keytermList());
                        $added = 0;
                        foreach (KeytermPolicy::sanitize(preg_split('/[\r\n,]+/', (string) $data['terms']) ?: []) as $term) {
                            if (KeytermPolicy::validate($term) !== null || in_array(mb_strtolower($term), $existing, true)) {
                                continue;
                            }
                            $client->keyterms()->create(['term' => $term]);
                            $existing[] = mb_strtolower($term);
                            $added++;
                        }
                        Notification::make()->title("Added {$added} keyterms")->success()->send();
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
