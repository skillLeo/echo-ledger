<?php

declare(strict_types=1);

namespace App\Services\Connector;

use App\Models\ConnectedApp;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client as OAuthClient;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

/**
 * The apps a person connected (since v1.5, docs/API.md "Claude and ChatGPT connector"): an app is connected when
 * Passport first issues it a token for that person (the code exchanged after "Allow"), is used whenever one of its tool
 * calls runs, and is disconnected from Profile → Connected apps, which revokes every access token, refresh token and
 * unused code it holds for that person. Its row stays, marked: the app keeps its client id and may be connected again.
 * Registrations no person ever connected are removed after a day.
 */
final class ConnectedApps
{
    /** Days a registered app may wait for its first connection before it is removed. */
    public const UNUSED_CLIENT_DAYS = 1;

    /** Seconds after a refresh in which the old token coming back is the app asking twice at once, not a leak. */
    public const REFRESH_RETRY_SECONDS = 10;

    public function __construct(private readonly AuditLog $audit) {}

    /** Passport's AccessTokenCreated: on a code exchange and on every refresh, so a connection starts only once. */
    public function tokenIssued(AccessTokenCreated $event): void
    {
        if ($event->userId === null || $event->userId === '') {
            return;
        }
        $app = ConnectedApp::query()->firstOrCreate(['user_id' => (int) $event->userId, 'client_id' => $event->clientId]);
        if ($app->disconnected_at !== null) {
            // Disconnecting revoked everything, so a new token means the person pressed Allow again: a new connection.
            $app->forceFill(['disconnected_at' => null, 'last_used_at' => null, 'created_at' => now()])->save();
        } elseif (! $app->wasRecentlyCreated) {
            return;
        }
        $name = self::name($event->clientId);
        $this->audit->record('connector_connected', (int) $event->userId, null, "{$name} connected to read your recordings", ['client' => $name, 'client_id' => $event->clientId]);
    }

    /** @return Collection<int, ConnectedApp> newest first */
    public function forUser(User $user): Collection
    {
        return ConnectedApp::query()->connected()->where('user_id', $user->id)->with('client')->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** A tool call of the app ran for the person. */
    public function used(User $user, string $clientId): void
    {
        ConnectedApp::query()->where('user_id', $user->id)->where('client_id', $clientId)->update(['last_used_at' => now()]);
    }

    /** Disconnect: the app loses access at once. False when it is not (or no longer) connected for this person. */
    public function disconnect(User $user, int $id): bool
    {
        $app = ConnectedApp::query()->connected()->where('user_id', $user->id)->find($id);
        if (! $app instanceof ConnectedApp) {
            return false;
        }
        $name = self::name($app->client_id);
        self::revoke($user->id, $app->client_id);
        $app->forceFill(['disconnected_at' => now()])->save();
        $this->audit->record('connector_disconnected', $user->id, null, "{$name} disconnected", ['client' => $name, 'client_id' => $app->client_id]);

        return true;
    }

    /** Every app of the person loses access (signing out everywhere, the account is erased). */
    public function disconnectAll(User $user): void
    {
        self::revoke($user->id, null);
        ConnectedApp::query()->connected()->where('user_id', $user->id)->update(['disconnected_at' => now()]);
    }

    /**
     * A used code came back: it leaked, or the app sent it twice, and nobody can tell which, so the connection ends and
     * the person connects again if it was the app.
     */
    public function codeReused(int $userId, string $clientId): void
    {
        $this->endConnection($userId, $clientId, 'code');
    }

    /**
     * A refresh token that was already swapped for a new one came back, so two parties hold that chain and nobody can
     * tell which is the app: the connection ends for both. Two cases are no such sign, and the token is only refused:
     * the app sending it twice at once, and a token from before the person last connected, which Disconnect already
     * threw away and which must not be able to end the connection made after it.
     */
    public function refreshTokenReused(int $userId, string $clientId, string $accessTokenId, int $expiresAt): void
    {
        $justSwapped = Passport::token()->newQuery()->whereKey($accessTokenId)->where('updated_at', '>', now()->subSeconds(self::REFRESH_RETRY_SECONDS))->exists();
        if ($justSwapped) {
            return;
        }
        $issuedAt = Carbon::createFromTimestamp($expiresAt)->sub(Passport::refreshTokensExpireIn());
        if (ConnectedApp::query()->connected()->where('user_id', $userId)->where('client_id', $clientId)->where('created_at', '>', $issuedAt)->exists()) {
            return;
        }
        $this->endConnection($userId, $clientId, 'refresh');
    }

    /** Removes registered apps that never got a token and are older than a day; how many. */
    public function pruneClients(): int
    {
        $old = Passport::client()->newQuery()->where('created_at', '<', now()->subDays(self::UNUSED_CLIENT_DAYS))
            ->whereNotIn('id', Passport::token()->newQuery()->select('client_id'))
            ->whereNotIn('id', Passport::authCode()->newQuery()->select('client_id'))
            ->whereNotIn('id', ConnectedApp::query()->select('client_id'));
        $count = 0;
        $old->get()->each(function (OAuthClient $client) use (&$count): void {
            $client->delete();
            $count++;
        });

        return $count;
    }

    /** The name the app registered with ("Claude", "ChatGPT"), or a plain fallback. */
    public static function name(?string $clientId): string
    {
        $name = $clientId === null ? null : Passport::client()->newQuery()->whereKey($clientId)->value('name');

        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'An app';
    }

    /** The app loses access at once, as with Disconnect, and the log says why. Nothing to do when it is not connected. */
    private function endConnection(int $userId, string $clientId, string $kind): void
    {
        $app = ConnectedApp::query()->connected()->where('user_id', $userId)->where('client_id', $clientId)->first();
        if (! $app instanceof ConnectedApp) {
            return;
        }
        $name = self::name($clientId);
        self::revoke($userId, $clientId);
        $app->forceFill(['disconnected_at' => now()])->save();
        $this->audit->record('connector_token_reused', $userId, null, "{$name} disconnected: a sign-in token it had already used came back, so someone else may hold a copy", ['client' => $name, 'client_id' => $clientId, 'kind' => $kind]);
    }

    /** Revokes the person's access and refresh tokens and unused codes, of one app or (null) of every app. */
    private static function revoke(int $userId, ?string $clientId): void
    {
        $scope = fn (Builder $query): Builder => $query->where('user_id', $userId)->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId));
        $tokens = $scope(Passport::token()->newQuery());
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        $scope(Passport::authCode()->newQuery())->update(['revoked' => true]);
    }
}
