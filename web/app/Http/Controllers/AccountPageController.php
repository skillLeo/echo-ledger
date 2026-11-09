<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Filament\Pages\WorkspaceSettings;
use App\Models\AccountExport;
use App\Models\User;
use App\Services\Accounts\AccountDeletion;
use App\Services\Accounts\GoogleSignIn;
use App\Services\SettingsRepository;
use App\Services\Storage\RecordingFiles;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public account pages (since v1.5, docs/API.md "Your account"): /account/delete, which Google Play links to
 * (sign in on the console's own page, confirm with the password, a two-step code or the linked Google account, done;
 * made for a phone), and the signed download link of "Export my data".
 */
final class AccountPageController extends Controller
{
    public function deletePage(Request $request): View|Response
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            // "Sign in to continue" comes back here after the console's sign-in.
            if ($request->boolean('sign_in')) {
                return redirect()->guest(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
            }

            return view('account.delete', ['user' => null]);
        }

        return view('account.delete', ['user' => $user, 'date' => $this->date($user), 'error' => $request->session()->get('account.delete.error'), ...$this->google($request, $user)]);
    }

    public function delete(Request $request, AccountDeletion $deletion): View|Response
    {
        /** @var User $user */
        $user = $request->user('web');
        if ($user->isBeingDeleted()) {
            return view('account.delete', ['user' => $user, 'date' => $this->date($user)]);
        }
        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:4096'],
            'code' => ['nullable', 'string', 'max:64'],
            'understood' => ['accepted'],
        ], ['understood.accepted' => 'Tick the box to confirm that you understand.']);
        // An account linked to Google that just chose that Google account again needs no password (one made with
        // Google has none it knows); two-step sign-in still asks for its code, as Google never stands in for it.
        $google = $this->google($request, $user);
        try {
            if (! $google['googleConfirmed']) {
                $deletion->confirm($user, $user->hasTwoFactor() ? null : ($data['password'] ?? null), $user->hasTwoFactor() ? ($data['code'] ?? null) : null);
            }
            $deletion->schedule($user);
        } catch (ApiError $e) {
            $team = $e->apiCode() === 'owner_of_team';

            return response()->view('account.delete', ['user' => $user, 'date' => null, 'error' => $e->getMessage(), 'workspaceUrl' => $team ? WorkspaceSettings::getUrl(panel: 'app') : null, ...$google], $e->getStatusCode() === 429 ? 429 : 422);
        }
        GoogleSignIn::forgetConfirmed($request->session());
        $date = $this->date($user->fresh() ?? $user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return view('account.deleted', ['date' => $date]);
    }

    /** The "Export my data" ZIP, decrypted as it is sent; the signed link works for its 24 hours. */
    public function download(string $export, RecordingFiles $files): StreamedResponse
    {
        $model = AccountExport::query()->find($export);
        abort_unless($model instanceof AccountExport && $model->downloadable() && $model->path !== null && $files->disk()->exists($model->path), 404);
        $path = $model->path;
        $name = 'EchoLedger export '.$model->created_at->format('Y-m-d').'.zip';

        return response()->streamDownload(function () use ($files, $path, $model): void {
            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                $files->decryptToStream($path, $out, $model->id);
                fclose($out);
            }
        }, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store', 'Content-Length' => (string) $model->bytes]);
    }

    /** @return array{googleConfirmed: bool, googleUrl: string|null} */
    private function google(Request $request, User $user): array
    {
        $offered = ! $user->hasTwoFactor() && $user->google_id !== null;

        return [
            'googleConfirmed' => $offered && GoogleSignIn::confirmed($request->session(), $user),
            'googleUrl' => $offered && app(GoogleSignIn::class)->configured() ? route('auth.google.redirect', ['intent' => 'confirm']) : null,
        ];
    }

    private function date(User $user): ?string
    {
        return $user->deletes_at?->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('F j, Y');
    }
}
