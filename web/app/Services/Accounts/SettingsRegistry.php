<?php

declare(strict_types=1);

namespace App\Services\Accounts;

/**
 * Every setting of the console's Settings (since v1.7), in the order the pages show them: one entry per row, and one
 * per page the root list opens. The pages render their rows from these entries, and Settings search reads them, so a
 * row and its search result never drift apart (SettingsIndexTest checks both ways).
 *
 * An entry: `id`, `title`, `page` (the Settings page it lives on, the address /app/settings/{page}), `section` (the
 * root header it sits under), `kind` (page, switch, choice, segmented, action, link, info, list, help), `description`,
 * `options` (labels, searchable for choice and segmented), `aliases` (titles searched but never shown), `keywords`,
 * `ru` (Roman Urdu), `ur` (Urdu), `requires` (what makes it available; see SettingsContext), `resettable`, `default`.
 * A help entry carries `answer` and `target` (the setting it opens).
 */
final class SettingsRegistry
{
    /** The root sections in their order, and the pages under each. */
    public const SECTIONS = [
        'Transcription' => ['language', 'transcription-key'],
        'App' => ['appearance', 'notifications', 'storage', 'shortcuts'],
        'Account' => ['profile', 'plan', 'security', 'devices', 'workspace', 'privacy'],
        'Support' => ['help'],
    ];

    /** Page slug => the id of its root row. */
    public const PAGES = [
        'language' => 'transcription.language',
        'transcription-key' => 'key',
        'appearance' => 'appearance',
        'notifications' => 'notifications',
        'storage' => 'storage',
        'shortcuts' => 'shortcuts',
        'profile' => 'profile',
        'plan' => 'plan',
        'security' => 'security',
        'devices' => 'devices',
        'workspace' => 'workspace',
        'privacy' => 'privacy',
        'help' => 'help',
    ];

    /** Shortcut queries: a query equal to a key lists these ids first (the available ones). */
    public const PINS = [
        'pro' => ['plan.upgrade'], 'ultra' => ['plan.upgrade'], 'premium' => ['plan.upgrade'], 'price' => ['plan.upgrade'], 'upgrade' => ['plan.upgrade'],
        'package' => ['plan'], 'plan' => ['plan'],
        'payment' => ['plan.payment_details'],
        '2fa' => ['security.two_step'], 'otp' => ['security.two_step'],
        'color' => ['appearance.wallpaper', 'appearance.theme'], 'colour' => ['appearance.wallpaper', 'appearance.theme'], 'rang' => ['appearance.wallpaper', 'appearance.theme'],
    ];

    /** @var list<string> */
    public const LANGUAGES = ['Automatic', 'English', 'Urdu', 'Arabic', 'French', 'German', 'Spanish', 'Italian', 'Portuguese', 'Dutch', 'Turkish', 'Russian'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $e = static fn (string $id, string $title, ?string $page, string $kind, array $more = []): array => [
            'id' => $id, 'title' => $title, 'page' => $page, 'kind' => $kind, 'section' => null, 'description' => '', 'options' => [],
            'aliases' => [], 'keywords' => [], 'ru' => [], 'ur' => [], 'requires' => [], 'resettable' => false, 'default' => null, ...$more,
        ];

        $list = [
            // Transcription
            $e('transcription.language', 'Language', 'language', 'choice', ['section' => 'Transcription', 'aliases' => ['Transcription language'],
                'description' => 'The language your recordings are transcribed in.', 'options' => self::LANGUAGES,
                'keywords' => ['language', 'roman urdu', 'english', 'urdu'], 'ru' => ['zuban', 'zaban', 'boli', 'angrezi', 'arabi', 'roman'],
                'ur' => ['زبان', 'اردو', 'انگریزی'], 'resettable' => true, 'default' => '']),
            $e('key', 'Transcription key', 'transcription-key', 'page', ['section' => 'Transcription', 'requires' => ['free_or_key' => true],
                'keywords' => ['groq', 'api key', 'own key', 'whisper', 'free transcription'], 'ru' => ['chabi', 'key'], 'ur' => ['چابی']]),
            $e('key.saved', 'Your Groq key', 'transcription-key', 'info', ['requires' => ['free_or_key' => true, 'key' => true], 'keywords' => ['saved key']]),
            $e('key.remove', 'Remove key', 'transcription-key', 'action', ['requires' => ['free_or_key' => true, 'key' => true], 'keywords' => ['delete key']]),

            // App
            $e('appearance', 'Appearance', 'appearance', 'page', ['section' => 'App', 'keywords' => ['look', 'display', 'theme'], 'ru' => ['shakal', 'rang'], 'ur' => ['ظاہری شکل']]),
            $e('appearance.theme', 'Theme', 'appearance', 'segmented', ['options' => ['System', 'Light', 'Dark'], 'resettable' => true, 'default' => 'system',
                'keywords' => ['dark mode', 'night mode', 'light mode', 'black'], 'ru' => ['raat', 'andhera', 'roshni', 'kala'], 'ur' => ['ڈارک', 'تھیم']]),
            $e('appearance.sidebar', 'Sidebar style', 'appearance', 'segmented', ['options' => ['Light', 'Dark', 'Soft grey'], 'resettable' => true, 'default' => 'light',
                'keywords' => ['sidebar colour', 'menu']]),
            $e('appearance.density', 'Density', 'appearance', 'segmented', ['options' => ['Comfortable', 'Compact'], 'resettable' => true, 'default' => 'comfortable',
                'keywords' => ['spacing', 'compact', 'dense']]),
            $e('appearance.clock', 'Times', 'appearance', 'segmented', ['options' => ['12-hour', '24-hour'], 'resettable' => true, 'default' => '12h',
                'keywords' => ['clock', 'time format', 'am pm'], 'ru' => ['waqt', 'ghanta'], 'ur' => ['وقت']]),
            $e('appearance.library_view', 'Library view', 'appearance', 'segmented', ['options' => ['List', 'Table'], 'resettable' => true, 'default' => 'list',
                'keywords' => ['table', 'list', 'layout']]),
            $e('notifications', 'Notifications', 'notifications', 'page', ['section' => 'App', 'keywords' => ['alerts', 'push', 'notification', 'email', 'emails'],
                'ru' => ['ittila', 'ittilaat', 'alert', 'ghanti'], 'ur' => ['اطلاع', 'اطلاعات']]),
        ];
        foreach (EmailPreferences::CATEGORIES as $key => $category) {
            $list[] = $e('notifications.email.'.$key, $category['label'], 'notifications', 'switch', [
                'keywords' => ['email', 'mail'], 'ru' => ['email'], 'ur' => ['ای میل'], 'resettable' => true, 'default' => $category['default']]);
        }
        $list = [...$list,
            $e('notifications.email.account', 'Account & security', 'notifications', 'info', [
                'keywords' => ['email', 'mail', 'sign in alert'], 'ru' => ['email'], 'ur' => ['ای میل']]),
            $e('notifications.email.plan_payments', 'Plan & payments', 'notifications', 'info', [
                'keywords' => ['email', 'mail', 'receipt email'], 'ru' => ['email'], 'ur' => ['ای میل']]),

            $e('storage', 'Storage', 'storage', 'page', ['section' => 'App', 'keywords' => ['space', 'storage', 'disk', 'full', 'gb'], 'ru' => ['jagah', 'memory'], 'ur' => ['جگہ', 'اسٹوریج']]),
            $e('storage.used', 'Your storage', 'storage', 'info', ['keywords' => ['used space', 'quota']]),
            $e('storage.retention', 'Recordings kept', 'storage', 'info', ['keywords' => ['retention', 'auto delete', 'how long'], 'ru' => ['kitne din']]),
            $e('storage.bin', 'Bin', 'storage', 'info', ['keywords' => ['trash', 'deleted', 'restore', 'recently deleted'], 'ru' => ['kachra', 'mitaya hua']]),
            $e('shortcuts', 'Keyboard shortcuts', 'shortcuts', 'page', ['section' => 'App', 'keywords' => ['hotkeys', 'keys', 'keyboard']]),

            // Account
            $e('profile', 'Profile', 'profile', 'page', ['section' => 'Account', 'keywords' => ['name', 'email', 'account', 'me'], 'ru' => ['naam', 'mera account'], 'ur' => ['پروفائل', 'نام']]),
            $e('profile.name', 'Name', 'profile', 'action', ['keywords' => ['change name'], 'ru' => ['naam'], 'ur' => ['نام']]),
            $e('profile.email', 'Email', 'profile', 'action', ['keywords' => ['change email'], 'ru' => ['email'], 'ur' => ['ای میل']]),
            $e('profile.workspace', 'Workspace', 'profile', 'info', ['keywords' => ['team', 'role'], 'ru' => ['team'], 'ur' => ['ٹیم'], 'requires' => ['team_member' => true]]),

            $e('plan', 'Plan & usage', 'plan', 'page', ['section' => 'Account', 'keywords' => ['plan', 'package', 'subscription', 'billing', 'usage', 'limits', 'minutes'],
                'ru' => ['package', 'pakage', 'plan', 'qeemat', 'keemat'], 'ur' => ['پلان', 'پیکج']]),
            $e('plan.current', 'Current plan', 'plan', 'info', ['keywords' => ['free', 'pro', 'ultra', 'my plan'], 'ru' => ['mera plan']]),
            $e('plan.upgrade', 'Upgrade', 'plan', 'action', ['requires' => ['can_upgrade' => true],
                'keywords' => ['upgrade', 'buy', 'premium', 'pro', 'ultra', 'price', 'pricing', 'subscribe'], 'ru' => ['khareedo', 'upgrade'], 'ur' => ['اپ گریڈ', 'خریدیں']]),
            $e('plan.usage.minutes', 'Transcription minutes', 'plan', 'info', ['keywords' => ['minutes left', 'limit', 'usage', 'quota'], 'ru' => ['minute', 'had'], 'ur' => ['منٹ']]),
            $e('plan.usage.storage', 'Storage', 'plan', 'info', ['keywords' => ['storage limit', 'gb'], 'ru' => ['jagah']]),
            $e('plan.usage.devices', 'Devices', 'plan', 'info', ['keywords' => ['device limit']]),
            $e('plan.usage.members', 'Team members', 'plan', 'info', ['keywords' => ['seats', 'people'], 'ru' => ['log'], 'requires' => ['seats' => true]]),
            $e('plan.compare', 'See every feature', 'plan', 'action', ['aliases' => ['Compare plans'], 'keywords' => ['features', 'difference', 'all features', "what's included"], 'ru' => ['farq'], 'ur' => ['موازنہ']]),
            $e('plan.promo', 'Promo code', 'plan', 'action', ['requires' => ['can_upgrade' => true],
                'keywords' => ['discount', 'coupon', 'voucher', 'offer'], 'ru' => ['discount', 'riayat', 'code'], 'ur' => ['رعایت', 'کوڈ']]),
            $e('plan.request', 'Upgrade request', 'plan', 'action', ['requires' => ['buyer' => true],
                'keywords' => ['request', 'pending', 'status', 'approval', 'waiting'], 'ru' => ['darkhwast', 'intezar'], 'ur' => ['درخواست']]),
            $e('plan.payment_details', 'Payment details', 'plan', 'action', ['requires' => ['buyer' => true],
                'keywords' => ['bank', 'iban', 'account number', 'raast', 'jazzcash', 'easypaisa', 'nayapay', 'sadapay', 'transfer', 'pay'],
                'ru' => ['bank', 'adaigi', 'paisay bhejo'], 'ur' => ['بینک', 'ادائیگی']]),
            $e('plan.receipt', 'Payment receipt', 'plan', 'action', ['requires' => ['buyer' => true],
                'keywords' => ['screenshot', 'proof', 'receipt', 'transaction id', 'tid', 'slip'], 'ru' => ['raseed', 'rasid', 'saboot', 'screenshot'], 'ur' => ['رسید', 'ثبوت']]),
            $e('plan.history', 'Payments', 'plan', 'list', ['requires' => ['buyer' => true], 'keywords' => ['history', 'past payments', 'invoice']]),

            $e('security', 'Sign-in & security', 'security', 'page', ['section' => 'Account', 'keywords' => ['security', 'sign in', 'login', 'password', '2fa'],
                'ru' => ['hifazat', 'login'], 'ur' => ['سیکیورٹی']]),
            $e('security.password', 'Password', 'security', 'action', ['keywords' => ['change password', 'reset password'], 'ru' => ['password badlo'], 'ur' => ['پاس ورڈ']]),
            $e('security.two_step', 'Two-step sign-in', 'security', 'action', ['keywords' => ['2fa', 'two factor', 'authenticator', 'otp', 'mfa', '2 step', 'two step', 'verification code']]),
            $e('security.recovery', 'Recovery codes', 'security', 'action', ['requires' => ['two_step' => true], 'keywords' => ['backup codes']]),
            $e('security.passkeys', 'Passkeys', 'security', 'list', ['requires' => ['full_console' => true], 'keywords' => ['passkey', 'fingerprint login', 'face', 'touch id']]),
            $e('security.google', 'Google sign-in', 'security', 'action', ['requires' => ['google' => true], 'keywords' => ['google', 'gmail', 'link google'], 'ru' => ['google']]),
            $e('security.browsers', 'Signed-in browsers', 'security', 'list', ['requires' => ['browsers' => true], 'keywords' => ['sessions', 'browsers', 'chrome']]),
            $e('security.activity', 'Sign-in activity', 'security', 'link', ['requires' => ['full_console' => true], 'keywords' => ['audit log', 'recent activity', 'history']]),
            $e('security.signout_all', 'Sign out everywhere', 'security', 'action', ['keywords' => ['log out all devices', 'logout everywhere'], 'ru' => ['sab se logout']]),

            $e('devices', 'Devices', 'devices', 'page', ['section' => 'Account', 'keywords' => ['phone', 'laptop', 'computer', 'paired', 'signed in'], 'ru' => ['mobile', 'laptop'], 'ur' => ['ڈیوائس']]),
            $e('devices.pair', 'Sign in a device', 'devices', 'action', ['keywords' => ['qr', 'pair', 'add device']]),
            $e('devices.item.reauth', 'Sign in again', 'devices', 'action', ['anchor' => 'devices', 'keywords' => ['expired device', 'reconnect', 'qr']]),
            $e('devices.item.forget', 'Remove from list', 'devices', 'action', ['anchor' => 'devices', 'keywords' => ['forget device', 'old device', 'signed out']]),
            $e('devices.connected', 'Claude and ChatGPT', 'devices', 'info', ['aliases' => ['Connected apps'], 'keywords' => ['connected apps', 'ai', 'disconnect', 'chatgpt', 'claude']]),

            $e('workspace', 'Workspace', 'workspace', 'page', ['section' => 'Account', 'requires' => ['workspace_admin' => true],
                'keywords' => ['team', 'members', 'invite', 'roles'], 'ru' => ['team', 'log'], 'ur' => ['ٹیم']]),

            $e('privacy', 'Privacy & data', 'privacy', 'page', ['section' => 'Account', 'keywords' => ['privacy', 'data', 'gdpr'], 'ru' => ['raazdari'], 'ur' => ['رازداری']]),
            $e('privacy.consent', 'Recording consent', 'privacy', 'action', ['keywords' => ['consent', 'law', 'legal', 'permission to record'], 'ru' => ['ijazat', 'qanoon'], 'ur' => ['اجازت']]),
            $e('privacy.export', 'Export my data', 'privacy', 'action', ['keywords' => ['download my data', 'backup', 'zip'], 'ru' => ['data download'], 'ur' => ['ڈیٹا']]),
            $e('privacy.support_access', 'Support access', 'privacy', 'choice', ['options' => ['Off', '1 hour', '1 day', '7 days'], 'keywords' => ['let support look', 'help access']]),
            $e('privacy.cancel_deletion', 'Cancel deletion', 'privacy', 'action', ['requires' => ['deleting' => true], 'keywords' => ['keep my account', 'undo delete', 'stop deletion']]),
            $e('privacy.delete_account', 'Delete my account', 'privacy', 'action', ['requires' => ['deleting' => false],
                'keywords' => ['close account', 'remove account', 'delete account'], 'ru' => ['account khatam', 'account delete', 'hatao'], 'ur' => ['اکاؤنٹ ختم']]),

            // Support
            $e('help', 'Help', 'help', 'page', ['section' => 'Support', 'keywords' => ['support', 'help', 'problem', 'not working'], 'ru' => ['madad', 'masla'], 'ur' => ['مدد']]),
            $e('help.apps', 'Get the apps', 'help', 'link', ['keywords' => ['download', 'install', 'phone app', 'computer app', 'record']]),

            // The root's own row
            $e('signout', 'Sign out', null, 'action', ['keywords' => ['log out', 'logout', 'sign off', 'exit', 'unpair'], 'ru' => ['logout', 'bahar niklo'], 'ur' => ['لاگ آؤٹ']]),
        ];

        foreach (self::HELP as $id => [$question, $answer, $target, $requires]) {
            $list[] = $e($id, $question, 'help', 'help', ['description' => $answer, 'answer' => $answer, 'target' => $target, 'requires' => $requires]);
        }

        return $list;
    }

    /** @var array<string, array{0: string, 1: string, 2: string, 3: array<string, bool>}> help entries: question, answer, target, requires */
    public const HELP = [
        'help.article.pay' => ['How do I pay for Pro or Ultra?', 'Choose Upgrade, pick a plan and send the amount to our bank account. Then add a screenshot of the receipt.', 'plan.upgrade', ['buyer' => true]],
        'help.article.approval_time' => ['How long does approval take?', 'We check receipts every day. Most are done within a few hours.', 'plan.request', ['buyer' => true]],
        'help.article.receipt' => ['How do I send my payment screenshot?', 'Open Settings › Plan & usage, open your request and choose Add receipt.', 'plan.receipt', ['buyer' => true]],
        'help.article.free_key' => ['How do transcripts work on Free?', 'On Free, transcripts use your own free Groq key. Create one at console.groq.com and paste it in Settings › Transcription key. It takes about 2 minutes.', 'key', ['free_or_key' => true]],
        'help.article.minutes_reset' => ['When do my minutes reset?', "Your minutes reset on the 1st of each month. Unused minutes don't carry over.", 'plan.usage.minutes', []],
        'help.article.dark_mode' => ['How do I turn on dark mode?', 'Go to Settings › Appearance › Theme and choose Dark.', 'appearance.theme', []],
        'help.article.delete' => ['How do I delete my account?', 'Go to Settings › Privacy & data › Delete my account. We erase your recordings and data.', 'privacy.delete_account', []],
    ];

    /** @return array<string, array<string, mixed>> every entry by id */
    public static function byId(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::entries() as $entry) {
                $map[(string) $entry['id']] = $entry;
            }
        }

        return $map;
    }

    /** The root section a page sits under ("App"), or null. */
    public static function sectionOf(?string $page): ?string
    {
        foreach (self::SECTIONS as $section => $pages) {
            if (in_array($page, $pages, true)) {
                return $section;
            }
        }

        return null;
    }

    /** The title of a page ("Appearance"), from its root row. */
    public static function pageTitle(string $page): string
    {
        $id = self::PAGES[$page] ?? null;

        return $id !== null ? (string) (self::byId()[$id]['title'] ?? $page) : $page;
    }
}
