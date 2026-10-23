# Web stack research: Laravel 13 + Filament 5.10 + Livewire 4 + Sanctum 4 + Pest 4 + Larastan 3

Verified 2026-10-24 against the **installed** source in `web/vendor` and the official docs. Every API fact cites a file
path (`web/vendor/...:line`) or a URL. Snippets marked **[ran]** were executed on this machine (PHP 8.5.10) from a
scratch directory; everything else is assembled from verified signatures but has **not** been run inside the app yet.
Anything that could not be confirmed is labelled **UNVERIFIED**.

## 0. Environment facts

| Package | Installed | Latest seen today | Source |
|---|---|---|---|
| laravel/framework | v13.38.0 | v13.38.0 (2026-10-20) | `web/vendor/composer/installed.json`, https://repo.packagist.org/p2/laravel/framework.json |
| filament/* (all 10 pkgs) | v5.10.3 | v5.10.3 (2026-10-20), no 6.x | installed.json, https://repo.packagist.org/p2/filament/filament.json |
| livewire/livewire | v4.4.8 | v4.4.8 (2026-10-21) | installed.json, https://repo.packagist.org/p2/livewire/livewire.json |
| laravel/sanctum | v4.3.3 | v4.3.3, no 5.x | https://repo.packagist.org/p2/laravel/sanctum.json |
| pestphp/pest | v4.7.8 (newest 4.x) | v5.3.3 (needs PHP ^8.4 + PHPUnit ^13.4) | https://repo.packagist.org/p2/pestphp/pest.json |
| pestphp/pest-plugin-laravel | v4.1.0 | - | installed.json |
| pestphp/pest-plugin-livewire | **not installed** | v4.1.0 supports pest ^4.3.1 + livewire ^4.0.1 | https://repo.packagist.org/p2/pestphp/pest-plugin-livewire.json |
| larastan/larastan | v3.13.1 | v3.13.2 (2026-10-23), allows illuminate ^13 | https://repo.packagist.org/p2/larastan/larastan.json |
| phpstan/phpstan / phpunit | 2.3.1 / 12.5.33 | - | installed.json |
| laravel/pint | v1.32.1 | - | installed.json |
| symfony/http-foundation | v8.1.9 | - | installed.json |
| pragmarx/google2fa, google2fa-qrcode, chillerlan/php-qrcode | v9.1.0, v4.0.0, 5.0.5 | - | installed.json |
| nesbot/carbon | 3.15.0 | - | installed.json |
| PHP / libsodium | 8.5.10 / 1.0.22 | - | `php -v`, `SODIUM_LIBRARY_VERSION` |
| Alpine.js (bundled in Livewire) | 3.17.5 | - | `version: "3.17.5"` in `web/vendor/livewire/livewire/dist/livewire.js` |

Facts about the current scaffold that matter for the build:
- **Table-name collision.** The default migration already creates a `sessions` table for the database session driver
  (`web/database/migrations/0001_01_01_000000_create_users_table.php:30-37`, `SESSION_DRIVER=database` in `web/.env`,
  `'table' => env('SESSION_TABLE', 'sessions')` in `web/config/session.php:89`). EchoLedger's recording sessions cannot use
  `sessions`. The class name `App\Models\Session` would also shadow the `Session` facade alias
  (`web/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:315`). Use `capture_sessions` / `CaptureSession`
  (the REST path can stay `/api/sessions`).
- **Composer requires PHP >= 8.4.1 at runtime.** `web/vendor/composer/platform_check.php` checks `PHP_VERSION_ID >= 80401`
  because the lock resolved Symfony 8.1 (`symfony/*` v8.1.x require `>=8.4.1`, installed.json). `composer.json` says
  `"php": "^8.3"` and Laravel 13 accepts `symfony/* ^7.4.0 || ^8.0.0` (installed.json, `laravel/framework` require). So a
  shared host on 8.3 needs `config.platform.php` pinned and a re-lock, or the host must run 8.4.1 or newer.
- **Local PHP limits:** `upload_max_filesize=2M`, `post_max_size=8M`, `memory_limit=1024M` (`ini_get`, php.ini
  `C:\tools\php\php.ini`). **A 5 MB *multipart* chunk fails locally.** A raw-body chunk is bounded only by
  `post_max_size` (8M). See §10. These limits belong to the CLI PHP 8.5.10 (`php artisan serve`). XAMPP's bundled
  `C:\xampp\php` is PHP 8.0.30 with 40M/40M limits and cannot run this lock file (needs >= 8.4.1), so do not test uploads
  through XAMPP Apache (corrected by verification: `php -r ini_get(...)` on both binaries, `C:\xampp\php\php.ini`).
- Current routes (`php artisan route:list`): `admin`, `admin/login`, `admin/logout`, `api/user`, `sanctum/csrf-cookie`,
  `livewire-a9460a6c/*` (Livewire 4 hashed endpoints), and **`GET|PUT storage/{path}`**. The last two exist because the
  `local` disk has `'serve' => true` (`web/config/filesystems.php:36`). They are signed-URL file serve/receive routes from
  `web/vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php:83-128`.
- `User` already uses the Laravel 13 attributes `#[Fillable]` and `#[Hidden]` (`web/app/Models/User.php:13-14`). It has no
  `HasApiTokens`, `FilamentUser` or MFA contracts yet. The panel CSRF middleware is `PreventRequestForgery`.
  `ValidateCsrfToken` and `VerifyCsrfToken` are now `@deprecated` aliases
  (`web/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/ValidateCsrfToken.php`).

---

## 1. Filament 5 multi-factor authentication (web login 2FA)

**Panel API** (`web/vendor/filament/filament/src/Panel/Concerns/HasAuth.php`):
- `multiFactorAuthentication(array|MultiFactorAuthenticationProvider|Closure $providers, string|Closure|array|null $setUpRequiredAction = SetUpRequiredMultiFactorAuthentication::class, bool|Closure $isRequired = false): static` (line 666)
- `requiresMultiFactorAuthentication(bool|Closure $condition = true)` (195), `profile(?string $page = EditProfile::class, bool $isSimple = true)` (269),
  `revealablePasswords()` (650), `multiFactorAuthenticationRoutePrefix()` (160, default `multi-factor-authentication`),
  `setUpRequiredMultiFactorAuthenticationRouteSlug()` (153, default `set-up`).
- The docs use the named argument `isRequired: true` (https://filamentphp.com/docs/5.x/users/multi-factor-authentication).
  It is the 3rd positional parameter, so always pass it **by name**.

**Providers.**
- `Filament\Auth\MultiFactor\App\AppAuthentication` (TOTP) has `getId()` = `'app'`.
  - `make()` resolves it from the container. `recoverable()`, `recoveryCodeCount(int)` (default 8),
    `regenerableRecoveryCodes(bool)`, `brandName(?string)` and `codeWindow(int)` (default 8 = ±4 min) are its options.
  - Codes are verified with replay protection through the default cache store.
  - Recovery codes are `Str::random(10).'-'.Str::random(10)`, stored `Hash::make`d. Verification takes a
    `lockForUpdate()` row lock.
  - Source: `web/vendor/filament/filament/src/Auth/MultiFactor/App/AppAuthentication.php:45-66,120-174,176-302,341-399`.
- `Filament\Auth\MultiFactor\Email\EmailAuthentication` has `getId()` = `'email_code'`.
  - The code is a 6-digit number, valid for 4 minutes (`codeExpiryMinutes()`).
  - At most 2 sends per rate-limit window. Sending goes through `$user->notify(...)`, so it needs working mail.
  - Source: `.../Auth/MultiFactor/Email/EmailAuthentication.php:38,49-52,84-90,149-156,371-381`.
- QR rendering needs no imagick: `filament/filament` requires `chillerlan/php-qrcode ^5.0` (installed.json).

**User model contracts and traits.** All are under `Filament\Auth\MultiFactor\...`.

| Need | Contract | Trait | Column (docs) | Auto cast / hidden added by trait |
|---|---|---|---|---|
| TOTP | `App\Contracts\HasAppAuthentication` | `App\Concerns\InteractsWithAppAuthentication` | `text('app_authentication_secret')->nullable()` | `'encrypted'` + hidden (`InteractsWithAppAuthentication.php:16-31`) |
| Recovery codes | `App\Contracts\HasAppAuthenticationRecovery` | `App\Concerns\InteractsWithAppAuthenticationRecovery` | `text('app_authentication_recovery_codes')->nullable()` | `'encrypted:array'` + hidden (`InteractsWithAppAuthenticationRecovery.php:17-32`) |
| Email code | `Email\Contracts\HasEmailAuthentication` | `Email\Concerns\InteractsWithEmailAuthentication` | `boolean('has_email_authentication')->default(false)` | `'boolean'` |
| Panel gate | `Filament\Models\Contracts\FilamentUser` (`canAccessPanel(Panel $panel): bool`) | - | e.g. `is_super_admin` | - |

The contract methods are `getAppAuthenticationSecret(): ?string`, `saveAppAuthenticationSecret(?string)`,
`getAppAuthenticationHolderName(): string`, `getAppAuthenticationRecoveryCodes(): ?array` and
`saveAppAuthenticationRecoveryCodes(?array)` (`.../App/Contracts/*.php`). The traits implement them and use `email` as the
holder name.

**[ran]** A test model with the `#[Hidden(['password','remember_token'])]` attribute plus both App traits ended up with:
- hidden = `[app_authentication_secret, app_authentication_recovery_codes, password, remember_token]`
- casts = `{encrypted, encrypted:array, hashed}`
- `toArray()` did not include the secret, and the raw attribute was ciphertext.

Both mechanisms use `mergeHidden()` (`web/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HidesAttributes.php:31-33`),
so the Laravel 13 attribute does not overwrite the trait's hidden fields.

**How enforcement works (important for design and tests).**
- **Login.** Filament's `Login::authenticate()` validates the password and checks `canAccessPanel()` first. If an enabled
  provider exists, it stores an encrypted `userUndertakingMultiFactorAuthentication` and shows the challenge form
  (statePath `data.multiFactor.{providerId}`). It only logs in (`attemptWhen` + `session()->regenerate()`) after the code
  validates. Password submissions are rate-limited to 5 per minute per IP (`rateLimit(5)`, keyed on component|method|IP by
  `danharrin/livewire-rate-limiting`), and MFA challenge attempts to 5 per user (`MultiFactorChallenge::$maxRateLimiterAttempts`)
  (`.../Auth/Pages/Login.php:67-180,269-285`; `MultiFactorChallenge.php:20,158-176`) (corrected by verification: Login.php:70,
  `web/vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27`, MultiFactorChallenge.php:173-176).
- **Route middleware.** With `isRequired: true`, every panel page gets the route middleware `EnsureMultiFactorAuthenticationIsEnabled`
  (`.../Pages/Concerns/HasRoutes.php:88-96`; resource pages inherit it because `Resources\Pages\Page extends Filament\Pages\Page`).
  That middleware only checks that the user **has an enabled provider** (secret filled). Otherwise it redirects to
  `/admin/multi-factor-authentication/set-up` (route `filament.admin.auth.multi-factor-authentication.set-up-required`)
  (`.../Http/Middleware/EnsureMultiFactorAuthenticationIsEnabled.php:12-19`, `web/vendor/filament/filament/routes/web.php:101-110`).
  No "challenge passed in this login" flag exists, so `actingAs()` bypasses the challenge entirely.
- **Not persistent.** The MFA middleware is not Livewire-persistent. The persistent list is `Authenticate`,
  `AuthenticateSession`, `SetUpPanel` and similar (`.../FilamentServiceProvider.php:147-157`).
- **Panel gate.** `Filament\Http\Middleware\Authenticate` aborts with 403 if the user is not a `FilamentUser` and
  `app.env !== 'local'`. That includes `APP_ENV=testing` (`.../Http/Middleware/Authenticate.php:35-40`).
- **Enrolment UI.** `->profile()` adds an MFA management section to `EditProfile` (`.../Auth/Pages/EditProfile.php:531-549`).
  The set-up-required page shows the same provider actions.
  - The setup modal asks for the current password, verifies a code and shows the recovery codes once
    (`.../App/Actions/SetUpAppAuthenticationAction.php:69-233`).
- **Cache store.** Challenge and replay state uses `Cache::store()` (the default store) and atomic locks when the store
  supports them (`.../MultiFactor/Concerns/HasCacheStore.php:14-51`). `CACHE_STORE=database` works on shared hosting.

```php
// web/app/Providers/Filament/AdminPanelProvider.php (additions)
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;            // web/vendor/filament/filament/src/Enums/ThemeMode.php
->login()
->profile(isSimple: false)
->multiFactorAuthentication([
    AppAuthentication::make()->recoverable()->brandName('EchoLedger'),
], isRequired: true)
->defaultThemeMode(ThemeMode::Dark)      // HasTheme.php:78; or ->darkMode(true, isForced: true) (HasDarkMode.php:15)
```

```php
// web/app/Models/User.php (shape)
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory, Notifiable, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->is_super_admin;   // keep is_super_admin out of #[Fillable]
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_super_admin' => 'boolean'];
    }
}
// migration: $table->text('app_authentication_secret')->nullable();
//            $table->text('app_authentication_recovery_codes')->nullable();
//            $table->boolean('is_super_admin')->default(false);
```

`php artisan make:filament-user` exists for seeding the first admin (`php artisan list filament`).

---

## 2. Resources in v5

**Generator** (`php artisan make:filament-resource --help`). Options: `--generate|-G` (columns from DB), `--view`,
`--simple|-S` (single Manage page with modals), `--embed-schemas`, `--embed-table`, `--not-embedded`, `--soft-deletes`,
`--model`, `--migration`, `--factory`, `--record-title-attribute=`, `--nested|-N`, `--cluster|-C`, `--panel=`.

The default layout (`web/vendor/filament/filament/src/Commands/MakeResourceCommand.php:484-626`) for model `CaptureSession` is:

```
app/Filament/Resources/CaptureSessions/CaptureSessionResource.php
app/Filament/Resources/CaptureSessions/Pages/{ListCaptureSessions,CreateCaptureSession,ViewCaptureSession,EditCaptureSession}.php
app/Filament/Resources/CaptureSessions/Schemas/{CaptureSessionForm,CaptureSessionInfolist}.php   // static configure(Schema $schema): Schema
app/Filament/Resources/CaptureSessions/Tables/CaptureSessionsTable.php                           // static configure(Table $table): Table
```

The resource delegates to them with `return CaptureSessionForm::configure($schema);`
(`.../FileGenerators/Resources/ResourceClassGenerator.php:195-264`).

The routes are `index` `/`, `create` `/create`, `view` `/{record}` and `edit` `/{record}/edit` (`MakeResourceCommand.php:524-545`).

**Signatures** (`web/vendor/filament/filament/src/Resources/Resource.php:57-128`):
- `public static function form(Schema $schema): Schema`, `infolist(Schema $schema): Schema`, `table(Table $table): Table`,
  `getRelations(): array`, `getPages(): array` (`'index' => ListX::route('/')`), `getEloquentQuery(): Builder`.
- The `Schema` class is `Filament\Schemas\Schema`. Layout components live in `Filament\Schemas\Components\*`
  (`Section`, `Grid`, `Tabs`, `Form`, `EmbeddedSchema`, `Actions`, `Text`, `View`, `Livewire`).
- Fields are `Filament\Forms\Components\*` and entries are `Filament\Infolists\Components\*`.
- The v3 classes `Filament\Forms\Components\Section`, `Filament\Forms\Form`, `Filament\Infolists\Infolist` and
  `Filament\Tables\Actions\*` **do not exist** in vendor (checked with Test-Path).
- **Property types are fixed by the parent.** You must redeclare them with exactly these types or PHP fatals
  (`.../Resources/Resource/Concerns/HasNavigation.php:22-34`):
  `protected static string|BackedEnum|null $navigationIcon`, `protected static string|UnitEnum|null $navigationGroup`,
  `?int $navigationSort`, `?string $navigationLabel`, `?string $slug` (`Resource/Concerns/HasRoutes.php:19`).
- The badge is `public static function getNavigationBadge(): ?string` (+ `getNavigationBadgeColor()`) (`HasNavigation.php:145-161`).
- Icons are the `Filament\Support\Icons\Heroicon` backed enum (`web/vendor/filament/support/src/Icons/Heroicon.php`). Cases
  verified: `OutlinedMicrophone`, `OutlinedDevicePhoneMobile`, `OutlinedComputerDesktop`, `OutlinedGlobeAlt`, `OutlinedUsers`,
  `OutlinedTag`, `OutlinedKey`, `OutlinedCog6Tooth`, `OutlinedChatBubbleLeftRight`, `OutlinedVideoCamera`, `OutlinedPlay`,
  `OutlinedTrash`, `OutlinedShieldCheck`, `OutlinedArrowUpTray`.

**Table API (v5 names).**
- The main calls are `->columns([])`, `->filters([])`, `->headerActions([])`, `->recordActions([])`,
  `->toolbarActions([BulkActionGroup::make([...])])`, `->defaultSort($col, 'desc')`, `->recordUrl()`, `->deferLoading()`,
  `->poll()` and `->modifyQueryUsing()` (`web/vendor/filament/tables/src/Table/Concerns/*.php`).
- The old `actions()` and `bulkActions()` are `@deprecated` (`HasRecordActions.php:140`, `HasBulkActions.php:33`).
- All actions come from `Filament\Actions\*`: `Action`, `CreateAction`, `ViewAction`, `EditAction`, `DeleteAction`,
  `DeleteBulkAction`, `BulkActionGroup` (`.../FileGenerators/Resources/Concerns/CanGenerateResourceTables.php:5-21,76-92`).

**Filters** (`web/vendor/filament/tables/docs/03-filters/*.md`).
- `SelectFilter::make('source_device')->options(SourceDevice::class)->multiple()`. Enum class strings are expanded through
  `Filament\Support\Contracts\HasLabel` (`web/vendor/filament/tables/src/Filters/Concerns/HasOptions.php:36-64`).
- `SelectFilter::make('client')->relationship('client', 'name')->searchable()->preload()`. The docs warn that `options()`
  is a UI affordance, not an authorization boundary (`02-select.md:26-30`).
- `TernaryFilter::make('x')->nullable()` or `->queries(true: fn ($q) => ..., false: ..., blank: ...)` (`03-ternary.md`).
- For date ranges use `Filter::make('started_at')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $q, array $data) => ...)`.
  **`->schema()`**; `->form()` is `@deprecated` (`web/vendor/filament/tables/src/Filters/Concerns/HasSchema.php:24-36`).

**Enums for source labels.** Implement `HasLabel`, `HasColor` and `HasIcon` (`web/vendor/filament/support/src/Contracts/*.php`):
- `getLabel(): string|Htmlable|null`
- `getColor(): string|array|null`
- `getIcon(): string|BackedEnum|Htmlable|null`

Cast the attribute to the enum. `TextColumn` and `TextEntry` then show the label, colour and icon automatically, and
`->badge()` renders it as a pill (https://filamentphp.com/docs/5.x/advanced/enums). That gives the "icon + text" source
labels.

**Relation manager** (`php artisan make:filament-relation-manager ClientResource keyterms term`).
- `protected static string $relationship` (`.../Resources/Concerns/InteractsWithRelationshipTable.php:25`).
- **Instance** methods `public function form(Schema $schema): Schema` and `table(Table $table): Table`
  (`.../FileGenerators/Resources/RelationManagerClassGenerator.php:113-245`).
- Register it with `getRelations(): [KeytermsRelationManager::class]`
  (https://filamentphp.com/docs/5.x/resources/managing-relationships).

```php
final class CaptureSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('source_device')->badge(),           // enum: HasLabel+HasIcon+HasColor
                TextColumn::make('source_app')->badge(),
                TextColumn::make('client.name')->searchable()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('source_device')->options(SourceDevice::class)->multiple(),
                SelectFilter::make('source_app')->options(SourceApp::class)->multiple(),
                SelectFilter::make('client')->relationship('client', 'name')->searchable()->preload(),
                Filter::make('started_at')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('started_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('started_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
```

---

## 3. Audio player + transcript with live highlight

**Options.**
1. **Custom entry class (recommended).** Run `php artisan make:filament-infolist-entry TranscriptPlayerEntry`. It creates
   `class X extends Filament\Infolists\Components\Entry { protected string $view = '...'; }` and a view at
   `resources/views/filament/infolists/components/...` (`web/vendor/filament/infolists/docs/09-custom-entries.md:9-26`).
   - The Blade view gets `$getState()`, `$record`, `$entry`, `$getEntryWrapperView()` and `$this` (the Livewire page).
   - Public methods on the class are callable as `$getX()`.
   - Methods marked `#[ExposedLivewireMethod]` (`Filament\Support\Components\Attributes\ExposedLivewireMethod`) can be
     called from JS with `$wire.callSchemaComponentMethod(@js($getKey()), 'method')`.
   - Entries are **not** Livewire components (`09-custom-entries.md:28-30,212-294`).
2. `ViewEntry::make('transcript')->view('...', [...])`. `ViewEntry` is an empty subclass of `Entry`
   (`web/vendor/filament/infolists/src/Components/ViewEntry.php`). `view(?string $view, array|Closure $viewData = [])`
   is at `web/vendor/filament/support/src/Components/ViewComponent.php:47`.
3. A schema `View::make('...')->viewData([...])`, or `Livewire::make(Component::class, [...])`. A nested Livewire component
   only sees static params and the record, and must re-authorize on its own
   (`web/vendor/filament/schemas/docs/09-custom-components.md:5-212`).

**Front-end facts.**
- Alpine is available: Livewire 4.4.8's `dist/livewire.js` embeds Alpine `3.17.5` and assigns `window.Alpine`.
  An `init()` method in `x-data` runs automatically (https://alpinejs.dev/directives/init).
- `$refs` works only on statically created elements, not inside `x-for` (https://alpinejs.dev/magics/refs).
- Wrap the player in `wire:ignore` so Livewire re-renders do not morph or reset the `<audio>` element
  (https://livewire.laravel.com/docs/4.x/wire-ignore).
- `timeupdate` fires at roughly 4-66 Hz (https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/timeupdate_event).
  Use `requestAnimationFrame` while playing for smooth word/segment highlight.
- **Tailwind classes in your own Blade do nothing without a custom theme.** You need `php artisan make:filament-theme`,
  `@source` lines, `npm run build`, and `->viteTheme('resources/css/filament/admin/theme.css')`
  (https://filamentphp.com/docs/5.x/styling/overview). Otherwise use inline styles or a scoped `<style>`.
- `@js()` emits `JSON.parse('...')` with `JSON_HEX_TAG|APOS|AMP|QUOT`, so it is safe inside a double-quoted `x-data`
  attribute (`web/vendor/laravel/framework/src/Illuminate/Support/Js.php:26-30,140`).

```blade
{{-- resources/views/filament/infolists/components/transcript-player-entry.blade.php --}}
@php
    $segments = $getState() ?? [];   // list<array{start: float, end: float, speaker: string, text: string}>, sorted by start
    $starts = array_map(fn (array $s): float => (float) $s['start'], $segments);
@endphp
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div wire:ignore x-data="{
            starts: @js($starts), active: -1, raf: null,
            init() {
                const a = this.$refs.audio;
                a.addEventListener('play', () => this.tick());
                ['pause', 'ended'].forEach(e => a.addEventListener(e, () => cancelAnimationFrame(this.raf)));
                a.addEventListener('seeked', () => this.sync());
            },
            tick() { this.sync(); this.raf = requestAnimationFrame(() => this.tick()); },
            sync() {
                const t = this.$refs.audio.currentTime; let lo = 0, hi = this.starts.length - 1, i = -1;
                while (lo <= hi) { const m = (lo + hi) >> 1; if (this.starts[m] <= t) { i = m; lo = m + 1; } else { hi = m - 1; } }
                if (i === this.active) return;
                const rows = this.$refs.list.children;
                if (this.active >= 0) rows[this.active]?.removeAttribute('data-active');
                if (i >= 0) { rows[i].setAttribute('data-active', ''); rows[i].scrollIntoView({ block: 'nearest' }); }
                this.active = i;
            },
            seek(i) { this.$refs.audio.currentTime = this.starts[i]; this.$refs.audio.play(); },
        }">
        <audio x-ref="audio" controls preload="metadata" style="width:100%"
               src="{{ route('admin.recordings.stream', $record->recording) }}"></audio>
        <ol x-ref="list" style="max-height:28rem;overflow-y:auto;list-style:none;padding:0">
            @foreach ($segments as $i => $s)
                <li x-on:click="seek({{ $i }})" style="cursor:pointer;padding:.25rem .5rem;border-radius:.375rem">
                    <strong>{{ $s['speaker'] }}</strong> {{ $s['text'] }}
                </li>
            @endforeach
        </ol>
        <style>[data-active]{background:color-mix(in oklch, var(--primary-500, oklch(0.769 0.188 70.08)) 18%, transparent)}</style>
    </div>
</x-dynamic-component>
```

That block was not run in a browser. Filament v5 emits `--primary-500` as a complete `oklch(...)` colour, not v3-style
RGB channels, so the earlier `rgb(var(--primary-500,245 158 11)/.18)` would be invalid CSS and the highlight would silently
not render. The style above uses `color-mix()` with the Amber 500 value as the fallback (corrected by verification:
`web/vendor/filament/support/src/Assets/AssetManager.php:286-324`, `web/vendor/filament/support/src/Colors/Color.php:209`). For thousands of words, keep the DOM to segments, or a `<span>` per word rendered by Blade,
never `x-for`.

---

## 4. Custom pages with forms (Settings, API Credentials)

- `Filament\Pages\BasePage` already `implements HasActions, HasRenderHookScopes, HasSchemas` and uses
  `InteractsWithActions` and `InteractsWithSchemas` (`web/vendor/filament/filament/src/Pages/BasePage.php:21-25`).
  Do **not** add v3-style `HasForms`/`InteractsWithForms`. In v5 they are compatibility shims (`HasForms extends HasSchemas`,
  `web/vendor/filament/forms/src/Contracts/HasForms.php`).
- **Schemas are discovered by method name.** A method `form(Schema $schema): Schema` becomes `$this->form`, and
  `content(Schema $schema)` becomes `$this->content` (`web/vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php:350-460`).
  The default page view `filament-panels::pages.page` renders only `{{ $this->content }}`
  (`web/vendor/filament/filament/resources/views/pages/page.blade.php`).
- `make:filament-page Settings` generates a `$view` property and an empty Blade wrapper
  (`.../Commands/FileGenerators/CustomPageClassGenerator.php:55-58`, `stubs/PageView.stub`). Either delete both and inherit
  the default view, or put `{{ $this->content }}` in the Blade.
- The pattern below is copied from the vendor `EditProfile` page: `Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->submit('save')->keyBindings(['mod+s'])])])`
  (`.../Auth/Pages/EditProfile.php:412-455,508-529`; `web/vendor/filament/schemas/src/Components/Form.php:58-68`).
  `Action::submit(?string)` also sets `wire:target` (`web/vendor/filament/actions/src/Action.php:461-472`).
- Authorize the page with `public static function canAccess(): bool` (https://filamentphp.com/docs/5.x/navigation/custom-pages).
- Send notifications with `Filament\Notifications\Notification::make()->title('Saved')->success()->send()`
  (`web/vendor/filament/notifications/docs/01-overview.md:9-29`).
- Secret inputs: `TextInput::make('x')->password()->revealable()->autocomplete('new-password')`
  (`web/vendor/filament/forms/docs/02-text-input.md:88-108,217-241`). `->dehydrated(bool|Closure)` is at
  `web/vendor/filament/schemas/src/Components/Concerns/HasState.php:262`.
- **Never `fill()` a decrypted secret.** Livewire public properties are dehydrated and sent to the browser, and must be
  treated as user input (https://livewire.laravel.com/docs/4.x/properties). Keep credential inputs write-only: show
  "stored, updated at X" as helper text and only overwrite fields the admin actually typed.

```php
/** @property-read Schema $form */   // tells Larastan about the magic property
final class ApiCredentials extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;
    protected static string|UnitEnum|null $navigationGroup = 'System';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool { return (bool) auth()->user()?->is_super_admin; }

    public function mount(): void { $this->form->fill(); }            // empty on purpose: write-only secrets

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('AssemblyAI')->schema([
                TextInput::make('assemblyai_api_key')->password()->revealable()->autocomplete('new-password')
                    ->helperText(fn () => Credential::describe('assemblyai_api_key')),   // e.g. "Stored, ends ...a1b2"
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s'])])]),
        ]);
    }

    public function save(): void
    {
        foreach (array_filter($this->form->getState(), 'filled') as $key => $value) {
            Credential::put($key, $value);                              // model with 'encrypted' cast
        }
        $this->form->fill();                                            // clear inputs again
        Notification::make()->title('Credentials saved')->success()->send();
    }
}
```

---

## 5. Dashboard widgets

- Create them with `php artisan make:filament-widget SourceCounts --stats-overview` (`--chart`, `--table`; see `--help`).
  `discoverWidgets(in: app_path('Filament/Widgets'), ...)` is already on the panel
  (`web/app/Providers/Filament/AdminPanelProvider.php:39`). Remove `FilamentInfoWidget` from `->widgets([])`.
- `StatsOverviewWidget::getStats(): array` returns `Stat::make(string|Htmlable $label, $value)`. Each stat supports
  `->description()`, `->descriptionIcon()`, `->icon()`, `->color()` and `->chart()`
  (`web/vendor/filament/widgets/src/StatsOverviewWidget/Stat.php:65-111`).
- **Polling defaults to 5 s** (`web/vendor/filament/widgets/src/Concerns/CanPoll.php:7`, used by both stats and chart
  widgets). Set `protected ?string $pollingInterval = null;` for the low-CPU, no-disturbance goal. Widgets are lazy by
  default; turn that off with `protected static bool $isLazy = false;` (`02-stats-overview.md:210-234`).
- `ChartWidget` declares **non-static** `protected ?string $heading` and `abstract protected function getType(): string`.
  `getData()` returns `['datasets' => [...], 'labels' => [...]]` in Chart.js shape (`web/vendor/filament/widgets/src/ChartWidget.php:30-81`).
  `TableWidget::$heading` is static (`TableWidget.php:31`), so do not mix them up.
- Order is controlled by `protected static ?int $sort` (`Widget.php:17`). The dashboard has 2 columns by default
  (`web/vendor/filament/filament/src/Pages/Dashboard.php:65-68`).

```php
final class SourceCounts extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $counts = CaptureSession::query()->toBase()->selectRaw('source_device, count(*) c')->groupBy('source_device')->pluck('c', 'source_device');
        return collect(SourceDevice::cases())->map(fn (SourceDevice $d) => Stat::make($d->getLabel(), (int) ($counts[$d->value] ?? 0))->icon($d->getIcon()))->all();
    }
}
```

---

## 6. Testing Filament with Pest 4

- `pestphp/pest-plugin-livewire` is **not installed**. `use function Pest\Livewire\livewire;` will fail until
  `composer require --dev pestphp/pest-plugin-livewire:^4.1` (v4.1.0 requires pest ^4.3.1 and livewire ^4.0.1). Its
  `livewire(string $name, array $params = [])` just forwards to `test()->livewire()`
  (https://raw.githubusercontent.com/pestphp/pest-plugin-livewire/v4.1.0/src/Autoload.php).
  Without it, use `Livewire\Livewire::test(Class::class, [...])` (`web/vendor/livewire/livewire/src/LivewireManager.php:238`).
- Filament registers its helpers as `Testable::mixin(...)` in the Schemas, Forms, Tables, Actions, Notifications and
  Infolists service providers (e.g. `web/vendor/filament/tables/src/TablesServiceProvider.php:52-57`). Verified names:
  - `fillForm(array|Closure $state = [], ?string $form = null)`, `assertHasFormErrors()`, `assertHasNoFormErrors(array $keys = [], ?string $form = null)`,
    `assertSchemaStateSet()` (`web/vendor/filament/forms/src/Testing/TestsForms.php:23-152`; `web/vendor/filament/schemas/src/Testing/TestsSchemas.php:163`).
  - `assertCanSeeTableRecords($records, bool $inOrder = false)`, `assertCanNotSeeTableRecords()`, `assertCountTableRecords(int)`,
    `loadTable()` (needed with `deferLoading()`), `searchTable(?string)`, `sortTable()`, `filterTable(string $name, $data = null)`
    (`web/vendor/filament/tables/src/Testing/TestsRecords.php`, `TestsColumns.php:625-643`, `TestsFilters.php:24-57`).
  - `filterTable` data mapping: a TernaryFilter takes `true|false|null` (becomes `['value' => ...]`). A SelectFilter takes
    a scalar, Model or BackedEnum, or an array for `multiple()`. A custom schema filter takes the array itself,
    e.g. `['from' => '2026-10-01']` (`TestsFilters.php:34-53`).
  - Actions: `callAction(string|TestAction|array $actions, array $data = [], array $arguments = [])`. For table rows use
    `callAction(TestAction::make('delete')->table($record))`; for bulk use `->table()->bulk()` after
    `selectTableRecords($ids)` (https://filamentphp.com/docs/5.x/testing/testing-actions;
    `web/vendor/filament/actions/src/Testing/TestAction.php:53-65`). `callTableAction($name, $record, $data)` still
    exists, is not deprecated, and wraps `callAction` (`web/vendor/filament/tables/src/Testing/TestsActions.php:62-73`).
  - `assertNotified()` / `assertNotNotified()` (`web/vendor/filament/notifications/src/Testing/TestsNotifications.php:17-28`).
- Custom pages default to the testing schema `'form'` when a `form` schema exists, otherwise `'content'`
  (`web/vendor/filament/filament/src/Pages/Page.php:629-632`). `fillForm([...])->call('save')` therefore needs no form name.
- **Auth in tests.**
  - `User` must implement `FilamentUser`, or every panel HTTP request returns 403 under `APP_ENV=testing`.
  - `livewire()`/`Livewire::test()` do not run route middleware, so the MFA-required redirect only matters for HTTP tests
    (`get('/admin')`). For those, give the user a secret through a factory state (the pattern Filament's own test factory
    uses: `'app_authentication_secret' => AppAuthentication::make()->generateSecret()`,
    https://raw.githubusercontent.com/filamentphp/filament/5.x/tests/database/factories/UserFactory.php).
  - To test the real TOTP login, follow Filament's own test (https://raw.githubusercontent.com/filamentphp/filament/5.x/tests/src/Panels/Auth/MultiFactor/App/AppAuthenticationTest.php):
    `livewire(Login::class)->fillForm(['email' => .., 'password' => 'password'])->call('authenticate')->fillForm(['app' => ['code' => $provider->getCurrentCode($user)]], 'multiFactorChallengeForm')->call('authenticate')->assertRedirect(Filament::getUrl())`.
    `getCurrentCode(HasAppAuthentication $user, ?string $secret = null)` is at `AppAuthentication.php:139`.

```php
// tests/Pest.php: pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use Filament\Actions\Testing\TestAction;
use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;                     // needs pest-plugin-livewire, or use Livewire::test()

beforeEach(fn () => actingAs(User::factory()->superAdmin()->withAppAuthentication()->create()));

it('filters the library by source device', function () {
    $phone = CaptureSession::factory()->phone()->create();
    $desk  = CaptureSession::factory()->desktop()->create();
    livewire(ListCaptureSessions::class)
        ->filterTable('source_device', [SourceDevice::Phone])          // multiple() select filter takes an array
        ->assertCanSeeTableRecords([$phone])->assertCanNotSeeTableRecords([$desk]);
});

it('deletes a session from the table', function () {
    $s = CaptureSession::factory()->create();
    livewire(ListCaptureSessions::class)->callAction(TestAction::make('delete')->table($s))->assertNotified();
    expect(CaptureSession::find($s->id))->toBeNull();
});

it('redirects users without MFA to set-up', function () {
    actingAs(User::factory()->superAdmin()->create())->get('/admin')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});
```

---

## 7. Laravel 13 specifics

### 7.1 What `install:api` did
`web/vendor/laravel/framework/src/Illuminate/Foundation/Console/ApiInstallCommand.php` did the following:
- required `laravel/sanctum:^4.0`
- published Sanctum's migration and config (`web/database/migrations/2026_10_23_212540_create_personal_access_tokens_table.php`,
  `web/config/sanctum.php`)
- copied `routes/api.php` (a sample `GET /api/user` behind `auth:sanctum`)
- added `api: __DIR__.'/../routes/api.php'` to `bootstrap/app.php`

It does **not** add `HasApiTokens` to any model; it only prints that instruction.

The token table uses `$table->morphs('tokenable')`, which is BIGINT ids by default (`Builder::$defaultMorphKeyType = 'int'`,
`web/vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php:56`). It also has `text('name')`,
`string('token', 64)->unique()`, `text('abilities')`, `last_used_at` and indexed `expires_at`.

### 7.2 Sanctum 4.3.3 tokens
- `createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)` returns `NewAccessToken`.
  `NewAccessToken` has public `$accessToken` and `$plainTextToken` (`web/vendor/laravel/sanctum/src/HasApiTokens.php:60-72`,
  `NewAccessToken.php:15`).
- The plaintext is `"{id}|{token_prefix}{Str::random(40)}{crc32b}"`. Only `sha256` of it is stored, and lookup uses
  `hash_equals` (`HasApiTokens.php:79-87`, `PersonalAccessToken.php:58-69`). `SANCTUM_TOKEN_PREFIX` feeds
  `config('sanctum.token_prefix')`.
- `tokenCan()`/`tokenCant()` check `abilities` with `'*'` as a wildcard (`PersonalAccessToken.php:77-81`).
  `currentAccessToken()->delete()` revokes the current token.
- The ability middleware aliases must be registered: `$middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class])`
  in `bootstrap/app.php` (https://laravel.com/docs/13.x/sanctum#token-ability-middleware). `CheckAbilities` throws
  `AuthenticationException` when there is no token (`web/vendor/laravel/sanctum/src/Http/Middleware/CheckAbilities.php:20-33`).
- **Guard order.** `Laravel\Sanctum\Guard::__invoke` first tries every guard in `config('sanctum.guard')` (`['web']`) and
  returns that user with a `TransientToken`, whose `can()` always returns `true` (the `TransientToken` is attached only
  when the session user's class uses `HasApiTokens`; otherwise the plain user is returned (corrected by verification:
  `Guard.php:32-37`)). Only then does it read the bearer token
  (`web/vendor/laravel/sanctum/src/Guard.php:30-62`, `TransientToken.php`).
  - EchoLedger uses bearer tokens only, so set `'guard' => []` in `config/sanctum.php`.
  - Also consider `'routes' => false` to drop `/sanctum/csrf-cookie` (`SanctumServiceProvider.php:68`).
- Token validity is `config('sanctum.expiration')` minutes from `created_at` (null means never) AND the token's own
  `expires_at` (`Guard.php:121-137`). You can veto tokens with
  `Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => $isValid && ...)` (`Sanctum.php:123`), for
  example to block revoked devices.
- Prune expired tokens with `sanctum:prune-expired --hours=24` (`Console/Commands/PruneExpired.php:17`).
- Test with `Sanctum::actingAs($user, $abilities = [], $guard = 'sanctum')`, which mocks a token. It calls
  `guard()->setUser($user)`, which is type-hinted `Authenticatable` (`Sanctum.php:70-93`,
  `web/vendor/laravel/framework/src/Illuminate/Auth/GuardHelpers.php:85`).

### 7.3 Can `Device` be the tokenable?
Yes. `tokens()` is `morphMany(..., 'tokenable')`, and the guard accepts any tokenable whose class uses the `HasApiTokens`
trait (`Guard::supportsTokens`, `Guard.php:70-75`). `$request->user()` then **returns the Device**. Requirements and
consequences:
- Device should `implements Illuminate\Contracts\Auth\Authenticatable` (use the `Illuminate\Auth\Authenticatable` trait).
  Otherwise `Sanctum::actingAs($device)` and `Auth::id()` break (`GuardHelpers.php:74-90`).
- Keep `devices.id` as an auto-increment BIGINT, or switch the morph type before the first production migrate.
- Larastan types `$request->user()` from guards that have a provider (`web/vendor/larastan/larastan/src/Concerns/LoadsAuthModel.php:17-46`,
  `ReturnTypes/RequestUserExtension.php`). With `sanctum.provider = null`, it reports `User|null`.
  - Add `'devices' => ['driver' => 'eloquent', 'model' => Device::class]` to providers and
    `'sanctum' => ['driver' => 'sanctum', 'provider' => 'devices']` to guards.
  - Larastan then infers `Device|null` for `$request->user('sanctum')`.
  - Sanctum then also **rejects user-owned tokens** on device routes (`Guard::hasValidProvider`, `Guard.php:145-154`).
- Tradeoff against "user tokens named per device":

| | Device tokenable (recommended) | User token per device |
|---|---|---|
| Revoke a device | `$device->tokens()->delete()` then delete the Device (morphMany does not cascade) | find the token by name/id |
| `last_seen`, platform, labels | live on the authenticated model | separate lookup by `currentAccessToken()->id` |
| Policies | `$device->user` for ownership | `$request->user()` is already the owner |
| Mixing with the Filament session user | impossible (good isolation) | `TransientToken` risk unless `sanctum.guard = []` |

### 7.4 Prunable vs MassPrunable
- `Prunable::pruneAll()` runs `chunkById` and calls `$model->prune()` per model. `prune()` calls `pruning()` and then
  `delete()` (or `forceDelete()` if soft-deletable), so **model events fire**. Exceptions per model are reported and
  pruning continues (`web/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Prunable.php:20-84`).
- `MassPrunable` deletes with limited query batches: **no `pruning()`, no deleting/deleted events**
  (`MassPrunable.php:16-39`; https://laravel.com/docs/13.x/eloquent#mass-pruning). Recordings own files, so they must use `Prunable`.
- `prunable()` is declared untyped in the trait. Override it as `public function prunable(): Builder` (as the docs do).
- `model:prune` options: `--model=*`, `--except=*`, `--path=*`, `--chunk=1000`, `--pretend`. It auto-discovers only
  `app/Models` (`web/vendor/laravel/framework/src/Illuminate/Database/Console/PruneCommand.php:25-30,118-162`).
  - `--path` is passed through `base_path()`, so give a relative path despite the "Absolute" help text.
  - A `$prunableChunkSize` property overrides `--chunk` (line 98).
- `Illuminate\Support\Carbon` is **mutable**. `$c = now(); $c->subDays(30)` changes `$c`. **[ran]** Chaining on a reused
  instance gave a wrong date. Build cutoffs inline: `now()->subDays($days)`.

### 7.5 Scheduler (routes/console.php)
- Use the `Illuminate\Support\Facades\Schedule` facade (https://laravel.com/docs/13.x/scheduling).
- `withoutOverlapping($expiresAt = 1440, ...)`: the mutex lives **24 h** by default (`web/vendor/laravel/framework/src/Illuminate/Console/Scheduling/ManagesAttributes.php:184-195`).
  A killed worker on shared hosting would block the queue for a day, so pass minutes.
- Queue worker flags (`php artisan queue:work --help`): `--stop-when-empty`, `--max-time`, `--max-jobs`, `--timeout`
  (default 60), `--tries` (default 1), `--memory` (default 128).
  - The timeout handler only works with `pcntl`: `supportsAsyncSignals()` returns `extension_loaded('pcntl')`
    (`web/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:326,1071-1074`). pcntl is absent on Windows and on
    many shared hosts.
- The `database` connection has `retry_after = 90` (`web/config/queue.php:43`). It must exceed the longest job.

```php
use Illuminate\Support\Facades\Schedule;
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')->everyMinute()->withoutOverlapping(5);
Schedule::command('model:prune')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
// cron: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1   (docs "Running the Scheduler")
```

### 7.6 Encryption: casts and Crypt
- Encrypted casts are `encrypted`, `encrypted:array`, `encrypted:collection`, `encrypted:json` and `encrypted:object`
  (`web/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:118-122`).
- The column must be TEXT or larger, and encrypted columns cannot be queried (https://laravel.com/docs/13.x/eloquent-mutators#encrypted-casting).
- Casts call `currentEncrypter()->encrypt($value, false)`, i.e. no PHP serialization (`HasAttributes.php:1459-1495`). So a
  raw `encrypted` column is readable with `Crypt::decryptString($raw)`, which is handy for the "encrypted at rest" test.
- The app cipher is `AES-256-CBC` with a 32-byte key and `previous_keys` from `APP_PREVIOUS_KEYS` (`web/config/app.php:98-106`).
  `Crypt::getKey()` returns the raw key bytes (`web/vendor/laravel/framework/src/Illuminate/Encryption/Encrypter.php:367-370`).

### 7.7 Fakes
- `Http::fake(['host/*' => Http::sequence()->push($body, 200)->pushStatus(500)->whenEmpty(Http::response())])`,
  `Http::fakeSequence()`, `Http::preventStrayRequests()`, `assertSent(fn (Request $r) => ...)`, `assertSentCount`,
  `assertNothingSent` (`web/vendor/laravel/framework/src/Illuminate/Http/Client/Factory.php:187-585`,
  `ResponseSequence.php:52-145`; https://laravel.com/docs/13.x/http-client#testing).
- `Storage::fake($disk = null, array $config = [])` cleans and swaps in a local disk, then use `assertExists`/`assertMissing`
  (`Support/Facades/Storage.php:106-129`; `Filesystem/FilesystemAdapter.php:131-204`).
- `Queue::fake($jobsToFake = [])` with `assertPushed($job, $cb)`, `assertPushedOn`, `assertNotPushed`, `assertNothingPushed`
  (`Support/Testing/Fakes/QueueFake.php:145-374`).
- Pest helpers (namespace `Pest\Laravel`) include `actingAs`, `travel`, `travelTo`, `freezeTime`, `withToken`,
  `postJson`, and `call($method, $uri, $params, $cookies, $files, $server, ?string $content)` for raw chunk bodies
  (`web/vendor/pestphp/pest-plugin-laravel/src/*.php`).

### 7.8 Rate limiting
- The `api` group is only `[SubstituteBindings]` (plus `EnsureFrontendRequestsAreStateful` if `statefulApi()`). There is
  **no throttle unless `$middleware->throttleApi()`** (`web/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:495-499,743`).
- Define limiters in `AppServiceProvider::boot()`:
  `RateLimiter::for('device', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));`
  and attach `throttle:device` (https://laravel.com/docs/13.x/routing#rate-limiting).

### 7.9 Audio streaming with Range + signed URLs
- `response()->file($file, $headers)` is `new BinaryFileResponse($file, 200, $headers)`, which is **public** by default
  (`web/vendor/laravel/framework/src/Illuminate/Routing/ResponseFactory.php:322-325`; `web/vendor/symfony/http-foundation/BinaryFileResponse.php:57`).
- `BinaryFileResponse::prepare()` handles a single `Range: bytes=a-b|a-|-n` (206 + `Content-Range`, or 416). It sets
  `Accept-Ranges: bytes` and honours `If-Range` (`BinaryFileResponse.php:193-303`). It also accepts an `\SplTempFileObject`
  (no MIME sniffing, so set `Content-Type`) (lines 76-108, 203-221).
- **[ran]** A 3 MB `SplTempFileObject` returned:
  - `bytes=0-99` → 206 with 100 bytes
  - `bytes=1000000-` → 206
  - `bytes=-500` → 206 with the last 500 bytes
  - `bytes=5000000-6000000` → 416
  - no Range → 200
  
  All bodies matched, with `Cache-Control: no-cache, private` when `public: false`.
- `new SplTempFileObject(-1)` keeps everything in memory. The default 2 MB threshold spills plaintext to the system temp dir
  (https://www.php.net/manual/en/spltempfileobject.construct.php).
- `Storage::disk()->response()` is a `StreamedResponse` using `fpassthru` and has **no Range support**
  (`FilesystemAdapter.php:346-372`).
- Signed URLs: `URL::temporarySignedRoute($name, $expiration, $params)` (`web/vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php:421`)
  plus the `signed` middleware alias (`Middleware.php:814`) or `ValidateSignature::relative()`.
  - Each seek re-requests the `src`, so the URL TTL must outlive the listening session.
- Unauthenticated non-JSON requests: the framework's default `redirectGuestsTo(fn () => route('login'))`
  (`.../Configuration/ApplicationBuilder.php:262`) throws "Route [login] not defined" here.
  - Set `$middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : route('filament.admin.auth.login'))`
    and `$exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*'))`.
  - The handler returns 401 JSON or 401 no-content (`.../Exceptions/Handler.php:854-867`).
  - The base `App\Http\Controllers\Controller` is empty, so use `Gate::authorize()`, not `$this->authorize()`.

---

## 8. Large-file encryption at rest (ext-sodium secretstream)

Signatures, from reflection on PHP 8.5.10 with libsodium 1.0.22 **[ran]**:
`sodium_crypto_secretstream_xchacha20poly1305_keygen(): string`, `..._init_push(string $key): array` (returns
`[$state, $header]`), `..._push(string &$state, string $message, string $additional_data = '', int $tag = 0): string`,
`..._init_pull(string $header, string $key): string`, `..._pull(string &$state, string $ciphertext, string $additional_data = ''): array|false`
(returns `[$plain, $tag]`), `..._rekey(string &$state): void`, `sodium_crypto_kdf_derive_from_key(int $subkey_length, int $subkey_id, string $context, string $key): string`,
`hash_hkdf(string $algo, string $key, int $length = 0, string $info = '', string $salt = ''): string`.

Constants: `HEADERBYTES=24`, `KEYBYTES=32`, `ABYTES=17`, `TAG_MESSAGE=0`, `TAG_PUSH=1`, `TAG_REKEY=2`, `TAG_FINAL=3`,
`KDF_CONTEXTBYTES=8`, `KDF_KEYBYTES=32`, `KDF_BYTES_MIN/MAX=16/64` (https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php,
`...-pull.php`).

Semantics from libsodium (https://doc.libsodium.org/secret-key_cryptography/secretstream):
- Each ciphertext is `len + ABYTES`, and messages cannot be truncated, removed, reordered or modified undetected.
- The header need not be secret.
- **The application itself must check that it saw `TAG_FINAL`** to detect truncation.
- Rekeying happens automatically.
- **Consequence: no random access.** Decrypting byte N means decrypting from the start, which matters for Range playback (§7.9).

**[ran]** This pattern round-tripped sizes 0, 1, 65535, 65536, 65537, 196608 and 1,000,003 bytes. It detected a dropped
final chunk ("no FINAL tag") and a flipped byte (`pull` returned `false`). Overhead is 45 bytes for one chunk plus 17 per
extra chunk. Sizes that are an exact multiple of the chunk size add an empty FINAL chunk.

```php
const CHUNK = 65536; const MAGIC = 'ELR1';                 // file = MAGIC(4) | header(24) | (CHUNK+17)* ...
function encryptFile(string $src, string $dst, string $key): void {
    $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    fwrite($out, MAGIC.$header);
    do {
        $chunk = fread($in, CHUNK); $eof = feof($in);
        fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk === false ? '' : $chunk, MAGIC,
            $eof ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
    } while (! $eof);
    fclose($in); fclose($out); sodium_memzero($state);
}
/** @return iterable<string> */
function decryptFile(string $src, string $key): iterable {
    $in = fopen($src, 'rb');
    if (fread($in, 4) !== MAGIC) throw new RuntimeException('bad magic');
    $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
    $final = false;
    while (! feof($in)) {
        $c = fread($in, CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
        if ($c === '' || $c === false) break;
        $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c, MAGIC);
        if ($res === false) throw new RuntimeException('tampered/corrupt chunk');
        [$plain, $tag] = $res; yield $plain;
        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) { $final = true; break; }
    }
    $trailing = ! feof($in) && fread($in, 1) !== ''; fclose($in);
    if (! $final || $trailing) throw new RuntimeException('truncated or trailing data');
}
```

Each yielded chunk is authenticated, but **truncation is only reported when the generator finishes**. Buffer everything
(e.g. into `SplTempFileObject(-1)`) before sending headers, or treat already-streamed bytes as provisional. Bind
`recording_id` into the additional data (e.g. `MAGIC.$recordingId`), so a ciphertext file cannot be swapped between
records.

**Keys.**
- **Dedicated key (recommended).** Generate a 32-byte key with `sodium_crypto_secretstream_xchacha20poly1305_keygen()`,
  store it base64-encoded in `.env` (e.g. `RECORDINGS_KEY`), back it up separately from the DB, and keep a `key_id`
  column per recording for rotation.
- **Derived from APP_KEY.** `sodium_crypto_kdf_derive_from_key(32, $keyId, 'ELREC_v1', Crypt::getKey())`. The context
  must be exactly 8 bytes and the key exactly 32. **[ran]** A wrong context raised `SodiumException: ... Argument #3
  ($context) must be SODIUM_CRYPTO_KDF_CONTEXTBYTES bytes long`. Alternatively use
  `hash_hkdf('sha256', Crypt::getKey(), 32, 'echoledger:recordings:v1')` (https://www.php.net/manual/en/function.hash-hkdf.php).
  - Downside: rotating APP_KEY silently orphans every file, because `APP_PREVIOUS_KEYS` only helps Laravel's own Encrypter.
  - With 30-day retention that risk is bounded but real.

---

## 9. Pest 4 setup, Larastan 3, Pint, PHP 8.5

- `vendor/bin/pest --init` copies `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` and the two example tests from
  `stubs/init-laravel/`. It **skips files that already exist**, so here it only creates `tests/Pest.php`
  (`web/vendor/pestphp/pest/src/Plugins/Init.php:28-110`).
  - The stub contains `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');` with the `use` line
    commented out (`stubs/init-laravel/Pest.php.stub`).
  - The existing PHPUnit class tests keep running under Pest.
- `php artisan test` switches to Pest when `function_exists('\Pest\version')` (`web/vendor/nunomaduro/collision/src/Adapters/Laravel/Commands/TestCommand.php:157-205`).
- `phpunit.xml` already sets sqlite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`,
  `MAIL_MAILER=array` and `BCRYPT_ROUNDS=4` (`web/phpunit.xml:20-35`).
- Larastan 3 (README, https://raw.githubusercontent.com/larastan/larastan/3.x/README.md) requires PHP 8.2+ and Laravel
  11.15+. The installed `composer.json` allows `illuminate/* ^13`. `vendor/nesbot/carbon/extension.neon` exists. Config:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon
parameters:
    paths: [app/]
    level: 6
```
  Run `vendor/bin/phpstan analyse --memory-limit=2G`.
- Pint: the default preset is `laravel`, so no `pint.json` is needed. Use `vendor/bin/pint --test` for CI and
  `--dirty` locally. Blade is not formatted unless `--blade` is passed (https://laravel.com/docs/13.x/pint).
- PHP 8.5 deprecations that bite app code (https://www.php.net/manual/en/migration85.deprecated.php):
  - `null` as an array offset (including in `array_key_exists`)
  - `(integer)`/`(boolean)`/`(double)`/`(binary)` casts
  - the backtick operator and `case ...;` semicolons
  - `curl_close()`, `finfo_close()`, `imagedestroy()`, `xml_parser_free()`
  - `Reflection*::setAccessible()`, `SplObjectStorage::attach/detach/contains`
  - `$http_response_header` (use `http_get_last_response_headers()`), `ord()` on multi-byte strings, `chr()` outside 0-255
  - `PDO::MYSQL_ATTR_*`. The scaffold already guards this with `(PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA)`
    and `use Pdo\Mysql;` (`web/config/database.php:4,63,83`). Collision ships `scripts/fix-pdo-constant.php` for the
    framework's own config.

---

## 10. Advertising a safe upload chunk size

- `ini_parse_quantity(string $shorthand): int` (PHP >= 8.2) parses `2M`, `1G`, `-1` and `0`. Invalid input gives
  `E_WARNING` and 0 or the leading number (https://www.php.net/manual/en/function.ini-parse-quantity.php). **[ran]**
  `parse('8M') = 8388608`.
- `Symfony\Component\HttpFoundation\File\UploadedFile::getMaxFilesize()` returns `min(post_max_size, upload_max_filesize)`,
  treating 0 as unlimited (`web/vendor/symfony/http-foundation/File/UploadedFile.php:238-244`).
- Laravel's global `ValidatePostSize` throws `PostTooLargeException` (413) whenever `CONTENT_LENGTH > post_max_size`, for
  **any** content type (`web/vendor/laravel/framework/src/Illuminate/Http/Middleware/ValidatePostSize.php`;
  global stack in `Middleware.php:455-462`).
  - Raw `application/octet-stream` chunks therefore avoid `upload_max_filesize` but not `post_max_size`.
  - Web-server limits (nginx `client_max_body_size`, Apache `LimitRequestBody`) apply on top.
- Read the limits **inside an HTTP request**, never in artisan or the queue: CLI ini values differ, and
  `max_execution_time` is always 0 in CLI.
- **[ran]** The snippet below, run from the CLI (same php.ini), returned `chunk_size=5242880`,
  `chunk_size_multipart=2097152` and `max_execution_time=0`.

```php
// e.g. in the /api/devices/register response or GET /api/upload-config
$q = static fn (string $k): int => ($v = ini_parse_quantity((string) ini_get($k))) > 0 ? $v : PHP_INT_MAX;
$raw = $q('post_max_size') - 64 * 1024;                       // raw octet-stream body: only post_max_size applies
$multipart = min($raw, $q('upload_max_filesize'));            // multipart file part: upload_max_filesize applies too
$align = static fn (int $n): int => max(256 * 1024, min(5 * 1024 * 1024, intdiv($n, 256 * 1024) * 256 * 1024));
return ['chunk_size' => $align($raw), 'chunk_size_multipart' => $align($multipart),   // this box: 5 MiB / 2 MiB
        'max_execution_time' => (int) ini_get('max_execution_time')];
```

---

## Recommendations for EchoLedger

1. **Rename the recording "sessions"** to table `capture_sessions` / model `CaptureSession`. Keep `/api/sessions` and the
   "Sessions" nav label (`protected static ?string $slug = 'sessions'`). Do not touch Laravel's `sessions` table.
2. **2FA.** Use `AppAuthentication::make()->recoverable()->brandName('EchoLedger')` with `isRequired: true` and
   `->profile(isSimple: false)`.
   - The User implements `FilamentUser` + `HasAppAuthentication` + `HasAppAuthenticationRecovery` and has `is_super_admin`.
   - Skip email MFA in v1 because it depends on SMTP. It can be added later as a second provider; the challenge then shows
     a provider picker.
3. **Device auth.**
   - Make `Device` the tokenable: `Model implements Authenticatable` + `HasApiTokens`.
   - Configure a `devices` provider, set the `sanctum` guard provider to `devices`, set `sanctum.guard => []` and
     `sanctum.routes => false`, and set a `SANCTUM_TOKEN_PREFIX`.
   - Give device tokens explicit abilities (`stt:token`, `sessions:write`, `recordings:upload`, `library:read`) and an
     `expires_at`. Revoke a device by deleting its row and tokens.
   - Register device pairing via a short-lived one-time code created by a Filament action on the Devices resource.
     That code is the only secret the device needs to call `POST /api/devices/register`.
4. **API hygiene.**
   - In `bootstrap/app.php`: register the `abilities`/`ability` aliases, call `redirectGuestsTo(...)` (api → null), and
     add `shouldRenderJsonWhen(api/*)`.
   - Rate-limit with named limiters (`device`, `uploads`, `webhooks`).
   - Use `Relation::enforceMorphMap(['user' => User::class, 'device' => Device::class])` (`Relation.php:542`), so tokens
     and Livewire payloads don't carry class names.
5. **Files.**
   - Add a dedicated `recordings` local disk under `storage/app/private/recordings` with **no** `serve` key.
   - Consider `'serve' => false` on `local` too, which removes the `GET|PUT storage/{path}` routes.
   - Encrypt the assembled two-channel file with secretstream (§8) in the assembly job, not inside the `/complete` request.
   - Store `plaintext_size`, `mime`, `key_id`, `sha256` and the chunk size.
6. **Playback.**
   - Admin route `GET /admin-media/recordings/{recording}` with `['web', 'auth', EnsureMultiFactorAuthenticationIsEnabled::class]`
     plus `Gate::authorize('view', $recording)`.
   - That middleware resolves `Filament::auth()` through the current-or-default panel, so it should work outside panel
     routes. This is derived from source and not yet run.
   - The controller decrypts into `new SplTempFileObject(-1)` and returns
     `new BinaryFileResponse($tmp, 200, ['Content-Type' => $mime], public: false)`, which gives verified Range support.
   - Device apps get a `URL::temporarySignedRoute(...)` URL, because WebView `<audio>` cannot send a bearer header.
   - If recordings may exceed what `memory_limit` allows, write a `StreamedResponse` that decrypts sequentially and emits
     only the requested range (single range only), using `plaintext_size` for `Content-Range`.
7. **Library UI.** Build a `CaptureSessionResource` with list and view pages only (`canCreate(): false`):
   - SelectFilters on the enum-backed `source_device` and `source_app`, a client relationship filter, and a date-range `Filter`
   - `ViewAction` / `DeleteAction` row actions
   - a view page infolist with a custom `TranscriptPlayerEntry` (§3)

   Use enums implementing `HasLabel`/`HasIcon`/`HasColor` everywhere, so phone, desktop and web items carry icon + text automatically.
8. **Settings / API Credentials** are custom pages (§4) with `canAccess()` limited to the super admin.
   - Credentials are write-only and use the `encrypted` cast on TEXT columns, and are never `fill()`ed back.
   - Retention days and provider-per-use-case go in Settings (plain values).
9. **Retention.**
   - Recordings and transcripts use `Prunable` (not MassPrunable) with `prunable(): Builder` reading the retention days
     setting.
   - Delete files in a `deleted` model event, which covers both the API delete and prune (Prunable fires events). Make it
     tolerant of missing files.
   - Schedule `model:prune` daily; `--pretend` is useful in DEPLOY.md.
10. **Scheduler.**
    - `queue:work --stop-when-empty --max-time=55 --tries=3` `everyMinute()->withoutOverlapping(5)`.
    - Keep jobs well under `retry_after` (90 s) or raise `DB_QUEUE_RETRY_AFTER`.
    - Cross-note conflict (flagged by verification): the STT notes run provider calls with HTTP timeouts up to 900 s
      (`stt-assemblyai.md` R5, `stt-elevenlabs.md` R3, `stt-azure-local.md` Recommendation 2, which asks for
      `--timeout=900` and `DB_QUEUE_RETRY_AFTER` above 900). With `--timeout` 60, `retry_after` 90 and `withoutOverlapping(5)`,
      such a job is killed (with pcntl) or re-dispatched while still running. Either raise all three together or split long
      provider calls into a separate worker.
    - Also schedule `sanctum:prune-expired --hours=24`.
11. **Chunk size.** Advertise it from the server (§10), capped at 5 MiB.
    - Send chunks as raw `application/octet-stream` bodies with `Content-Range`/offset headers plus a per-chunk SHA-256.
      Only `post_max_size` limits them: 5 MiB is fine on this box, while multipart would be capped at 2 MiB.
    - Stream them to disk with `stream_copy_to_stream($request->getContent(true), $fh)`
      (`Request::getContent(bool $asResource)` is at `web/vendor/symfony/http-foundation/Request.php:1559`).
12. **Tooling.**
    - `composer require --dev pestphp/pest-plugin-livewire:^4.1` (project decision), `vendor/bin/pest --init`, and
      enable `RefreshDatabase` in `tests/Pest.php`.
    - Add `phpstan.neon` at level 6 and `@property-read Schema $form` on pages.
    - Pin `config.platform.php` to the production PHP version before deploying.
13. **Dashboard widgets:** `$pollingInterval = null`, and drop `FilamentInfoWidget`. Disable the panel's global search if
    unused (`globalSearch(false)`, `HasGlobalSearch.php:31`).

## Gotchas

- `sessions` is already Laravel's session table, and `Session` is a facade alias (§0).
- Resource and Page static properties must keep the parent's union types (`string|BackedEnum|null` for icons,
  `string|UnitEnum|null` for groups). `ChartWidget::$heading` is an **instance** property, while `TableWidget::$heading` is static.
- v3 tutorials are wrong for v5:
  - `Filter::form()`, `Table::actions()` and `Table::bulkActions()` are deprecated.
  - `Filament\Forms\Form`, `Filament\Tables\Actions\*` and `Filament\Forms\Components\Section` are gone.
  - Layout is `Filament\Schemas\Components\*`.
- Arbitrary Tailwind classes in custom Blade views don't render without a custom theme and a Vite build.
- Without `FilamentUser`, every panel request is 403 outside `APP_ENV=local`, including tests.
- MFA "required" only checks that a secret exists. The challenge happens only in Filament's Login, and `actingAs()` skips
  it. Livewire component tests skip route middleware entirely.
- The default `withoutOverlapping()` lock is 24 h. `queue:work --timeout` needs pcntl, which shared hosts may lack.
- `response()->file()` is cacheable as public by default. `Storage::response()` has no Range support. A default
  `SplTempFileObject` spills plaintext to `/tmp` past 2 MB.
- Sanctum session-first guard order plus `TransientToken::can() === true` means a web-session user passes every
  `tokenCan()`. Set `sanctum.guard => []`.
- Ability middleware aliases are not registered by default. Neither is API throttling (`api` group has no throttle).
- No `login` route exists, so guest redirects from `auth` middleware 500 unless `redirectGuestsTo` is set.
- `MassPrunable` skips `pruning()` and model events, so files would be orphaned.
- `model:prune --path` is joined with `base_path()`. Discovery covers only `app/Models`.
- `Illuminate\Support\Carbon` (`now()`) is mutable.
- Secretstream has no random access. Range playback must decrypt from the start, and the reader must enforce `TAG_FINAL`.
- Deriving the file key from APP_KEY ties recordings to APP_KEY rotation.
- The local dev `upload_max_filesize=2M` makes 5 MB *multipart* chunks fail. `ValidatePostSize` (413) rejects bodies over
  `post_max_size` even for raw octet streams.
- The lock file needs PHP >= 8.4.1 (`platform_check.php`). `composer.json` claiming ^8.3 is misleading.
- `pest-plugin-livewire` is missing, so `use function Pest\Livewire\livewire` fatals.
- Livewire 4 moved its endpoint to `/livewire-{hash}/` (https://livewire.laravel.com/docs/4.x/upgrading). Shared-host WAF
  rules keyed on `/livewire/` need updating.
- Larastan types `$request->user()` from guards that have a provider, so Device-tokenable needs the `devices` provider
  (or explicit `instanceof` checks).

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | v5 MFA = `->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)`; User needs `HasAppAuthentication(+Recovery)` contracts + `InteractsWith*` traits + TEXT columns `app_authentication_secret` / `app_authentication_recovery_codes`; traits add `encrypted` / `encrypted:array` casts + hidden | `web/vendor/filament/filament/src/Panel/Concerns/HasAuth.php:666`; `.../Auth/MultiFactor/App/Concerns/*.php`; https://filamentphp.com/docs/5.x/users/multi-factor-authentication; [ran] cast/hidden check | high |
| 2 | MFA enforcement is the route middleware `EnsureMultiFactorAuthenticationIsEnabled` (checks only that a provider is enabled) + the challenge inside `Login::authenticate`; `actingAs` bypasses it; `FilamentUser` is required outside `local` | `.../Auth/MultiFactor/Http/Middleware/EnsureMultiFactorAuthenticationIsEnabled.php`; `.../Pages/Concerns/HasRoutes.php:88-96`; `.../Auth/Pages/Login.php:67-180`; `.../Http/Middleware/Authenticate.php:35-40` | high |
| 3 | Panel pages already have HasSchemas/HasActions; the form page pattern is `form(Schema)` + `content(Schema)` with `Form::make([EmbeddedSchema::make('form')])->livewireSubmitHandler('save')`; default testing schema is `'form'` | `.../Pages/BasePage.php:21-25`; `.../Auth/Pages/EditProfile.php:508-529`; `.../Pages/Page.php:629-632` | high |
| 4 | Resource API: static `form/infolist(Schema)`, `table(Table)`; nav property union types; `recordActions`/`toolbarActions`; `Filter::schema()`; generator writes `Schemas/` + `Tables/` classes | `.../Resources/Resource.php:57-128`; `.../Resource/Concerns/HasNavigation.php:22-34`; `.../FileGenerators/Resources/Concerns/CanGenerateResourceTables.php:76-92`; `MakeResourceCommand.php:548-626` | high |
| 5 | Alpine (3.17.5) is bundled with Livewire 4.4.8 and usable in Filament Blade views; use `wire:ignore` around the player | `web/vendor/livewire/livewire/dist/livewire.js`; https://livewire.laravel.com/docs/4.x/wire-ignore | medium |
| 6 | `BinaryFileResponse` (symfony/http-foundation 8.1.9) serves single-range requests (206/416), also from `SplTempFileObject`; `response()->file()` is public by default | `web/vendor/symfony/http-foundation/BinaryFileResponse.php:57-108,193-303`; `ResponseFactory.php:322-325`; [ran] range test | high |
| 7 | Sanctum 4.3.3 `createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)`; any model using `HasApiTokens` can own tokens; `Sanctum::actingAs` needs `Authenticatable`; session guards are tried first (`TransientToken` can-all) | `web/vendor/laravel/sanctum/src/HasApiTokens.php:60`; `Guard.php:30-75`; `Sanctum.php:70-93`; `GuardHelpers.php:85` | high |
| 8 | `Prunable` deletes per model (fires `pruning()` + events); `MassPrunable` does not; `model:prune` auto-discovers `app/Models` only | `.../Database/Eloquent/Prunable.php`, `MassPrunable.php`; `.../Database/Console/PruneCommand.php:118-162`; https://laravel.com/docs/13.x/eloquent#pruning-models | high |
| 9 | `withoutOverlapping()` lock defaults to 1440 minutes; pass a small value for the per-minute queue worker | `.../Console/Scheduling/ManagesAttributes.php:184`; https://laravel.com/docs/13.x/scheduling | high |
| 10 | secretstream API/constants as listed; header 24 B, ABYTES 17, app must enforce `TAG_FINAL`; kdf context exactly 8 B, key 32 B | reflection on PHP 8.5.10 / libsodium 1.0.22; https://doc.libsodium.org/secret-key_cryptography/secretstream; php.net pages; [ran] round-trip/tamper test | high |
| 11 | `ValidatePostSize` (global) rejects any body over `post_max_size`; local limits are 2M/8M, so 5 MB multipart chunks fail (raw bodies only need to fit under 8M); compute chunk size per request via `ini_get` + `ini_parse_quantity` | `web/vendor/laravel/framework/src/Illuminate/Http/Middleware/ValidatePostSize.php`; `Middleware.php:455-462`; [ran] `ini_get` | high |
| 12 | Default migration owns the `sessions` table; the lock file needs PHP >= 8.4.1 at runtime | `web/database/migrations/0001_01_01_000000_create_users_table.php:30`; `web/vendor/composer/platform_check.php` | high |

## Open questions

1. What PHP version and limits does the production shared host have (>= 8.4.1? `post_max_size`, `upload_max_filesize`,
   `memory_limit`, `max_execution_time`, pcntl, proc_open)? This decides between pinning `config.platform.php` and
   in-memory vs streamed decryption.
2. May we add `pestphp/pest-plugin-livewire:^4.1` (dev)? Otherwise tests use `Livewire::test()`.
3. Recording key: a dedicated `.env` key with offline backup, or one derived from APP_KEY? Who keeps the backup if the
   server dies?
4. Will Node/Vite run before deploys (custom Filament theme for a premium dark look), or must custom views avoid Tailwind
   classes (inline styles only)?
5. What transcript JSON shape do providers return (word vs segment timestamps, channel/speaker fields)? The player
   assumes sorted `{start, end, speaker, text}` segments. To be fixed by the provider research notes.
6. Device pairing UX: one-time code from a Filament action, or a QR containing base URL + code + pinning fingerprint?
7. Are sessions themselves pruned or kept as tombstones for `?since=` sync, and for how long?
8. **Resolved:** `--primary-500` exists and holds a full `oklch(...)` colour, so the player highlight now uses `color-mix()`
   (corrected by verification: `web/vendor/filament/support/src/Assets/AssetManager.php:286-324`).
