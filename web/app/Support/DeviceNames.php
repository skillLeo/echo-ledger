<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Readable device names (since v1.6, P3): what a phone or computer sent as its name (Android sends the maker and the
 * model code, "samsung SM-S938B"; the desktop its host name) becomes what people call it ("Galaxy S25 Ultra",
 * "Windows 11 PC", "MacBook Pro"). A name the person typed or chose with Rename stays as it is.
 */
final class DeviceNames
{
    /** Samsung model codes (the letters after the digits are the region and are ignored). */
    public const SAMSUNG = [
        'SM-S938' => 'Galaxy S25 Ultra', 'SM-S937' => 'Galaxy S25 Edge', 'SM-S936' => 'Galaxy S25+', 'SM-S931' => 'Galaxy S25',
        'SM-S731' => 'Galaxy S25 FE',
        'SM-S928' => 'Galaxy S24 Ultra', 'SM-S926' => 'Galaxy S24+', 'SM-S921' => 'Galaxy S24', 'SM-S721' => 'Galaxy S24 FE',
        'SM-S918' => 'Galaxy S23 Ultra', 'SM-S916' => 'Galaxy S23+', 'SM-S911' => 'Galaxy S23', 'SM-S711' => 'Galaxy S23 FE',
        'SM-S908' => 'Galaxy S22 Ultra', 'SM-S906' => 'Galaxy S22+', 'SM-S901' => 'Galaxy S22',
        'SM-G998' => 'Galaxy S21 Ultra', 'SM-G996' => 'Galaxy S21+', 'SM-G991' => 'Galaxy S21', 'SM-G990' => 'Galaxy S21 FE',
        'SM-F966' => 'Galaxy Z Fold7', 'SM-F766' => 'Galaxy Z Flip7', 'SM-F956' => 'Galaxy Z Fold6', 'SM-F741' => 'Galaxy Z Flip6',
        'SM-F946' => 'Galaxy Z Fold5', 'SM-F731' => 'Galaxy Z Flip5', 'SM-F936' => 'Galaxy Z Fold4', 'SM-F721' => 'Galaxy Z Flip4',
        'SM-A566' => 'Galaxy A56', 'SM-A556' => 'Galaxy A55', 'SM-A546' => 'Galaxy A54', 'SM-A536' => 'Galaxy A53',
        'SM-A366' => 'Galaxy A36', 'SM-A356' => 'Galaxy A35', 'SM-A346' => 'Galaxy A34',
        'SM-A266' => 'Galaxy A26', 'SM-A256' => 'Galaxy A25', 'SM-A245' => 'Galaxy A24',
        'SM-A166' => 'Galaxy A16', 'SM-A165' => 'Galaxy A16', 'SM-A156' => 'Galaxy A15', 'SM-A155' => 'Galaxy A15',
        'SM-A065' => 'Galaxy A06', 'SM-A055' => 'Galaxy A05', 'SM-M556' => 'Galaxy M55', 'SM-M356' => 'Galaxy M35',
        'SM-X920' => 'Galaxy Tab S10 Ultra', 'SM-X820' => 'Galaxy Tab S10+', 'SM-X910' => 'Galaxy Tab S9 Ultra', 'SM-X810' => 'Galaxy Tab S9+',
        'SM-X710' => 'Galaxy Tab S9',
    ];

    /** Lower-case maker names Android sends, as people write them. */
    private const MAKERS = ['xiaomi' => 'Xiaomi', 'redmi' => 'Redmi', 'poco' => 'POCO', 'oneplus' => 'OnePlus', 'oppo' => 'OPPO', 'vivo' => 'vivo',
        'realme' => 'realme', 'motorola' => 'Motorola', 'nokia' => 'Nokia', 'huawei' => 'Huawei', 'honor' => 'HONOR', 'infinix' => 'Infinix',
        'tecno' => 'TECNO', 'itel' => 'itel', 'nothing' => 'Nothing', 'sony' => 'Sony', 'asus' => 'ASUS', 'lenovo' => 'Lenovo', 'zte' => 'ZTE'];

    /**
     * The name to show for a device: [$name] as typed or renamed ([$chosen]), else made readable from what the app sent.
     */
    public static function readable(string $name, string $platform, ?string $osVersion, bool $chosen = false): string
    {
        $name = trim($name);
        if ($chosen && $name !== '') {
            return $name;
        }

        return match ($platform) {
            'android', 'ios' => self::phone($name, $platform),
            'windows' => self::looksAutomatic($name) || self::looksLikeWindows($name) ? self::windows($osVersion) : $name,
            'macos' => self::mac($name),
            default => $name !== '' ? $name : self::fallback($platform, $osVersion),
        };
    }

    /** A name for a device that sent none (the sign-up of an app that does not name itself). */
    public static function fallback(string $platform, ?string $osVersion): string
    {
        return match ($platform) {
            'android' => 'Android phone',
            'ios' => 'iPhone',
            'windows' => self::windows($osVersion),
            'macos' => 'Mac',
            default => 'Linux computer',
        };
    }

    private static function phone(string $name, string $platform): string
    {
        if ($name === '') {
            return self::fallback($platform, null);
        }
        if (preg_match('/^(?:samsung\s+)?(SM-[A-Z]\d{3})[A-Z0-9\/]*$/i', $name, $m) === 1) {
            return self::SAMSUNG[strtoupper($m[1])] ?? 'Galaxy '.strtoupper($m[1]);
        }
        if (preg_match('/^google\s+(pixel.*)$/i', $name, $m) === 1) {
            return Str::of($m[1])->replaceMatches('/^pixel/i', 'Pixel')->toString();
        }
        $first = Str::lower(Str::before($name, ' '));
        if (isset(self::MAKERS[$first]) && str_contains($name, ' ')) {
            return self::MAKERS[$first].' '.Str::after($name, ' ');
        }
        if ($first === 'samsung' && str_contains($name, ' ')) {
            return 'Samsung '.Str::after($name, ' ');
        }

        return $name;
    }

    /** "Windows 11 PC" from the desktop's os_version ("build 26100", or "10.0.26100"). */
    private static function windows(?string $osVersion): string
    {
        if ($osVersion !== null && preg_match('/(\d{5,})/', $osVersion, $m) === 1) {
            return (int) $m[1] >= 22000 ? 'Windows 11 PC' : 'Windows 10 PC';
        }

        return 'Windows PC';
    }

    private static function mac(string $name): string
    {
        foreach (['/macbook[\s_-]*pro/i' => 'MacBook Pro', '/macbook[\s_-]*air/i' => 'MacBook Air', '/macbook/i' => 'MacBook', '/imac/i' => 'iMac',
            '/mac[\s_-]*mini/i' => 'Mac mini', '/mac[\s_-]*studio/i' => 'Mac Studio', '/mac[\s_-]*pro/i' => 'Mac Pro'] as $pattern => $readable) {
            if (preg_match($pattern, $name) === 1) {
                return $readable;
            }
        }

        return self::looksAutomatic($name) || preg_match('/(\.local$|\bmac\b)/i', $name) === 1 ? 'Mac' : $name;
    }

    /** A host name or the apps' own placeholder rather than something a person typed. */
    private static function looksAutomatic(string $name): bool
    {
        return $name === '' || in_array(Str::lower($name), ['desktop', 'computer', 'pc', 'laptop'], true)
            || preg_match('/^(DESKTOP|LAPTOP|WIN|PC)-[A-Z0-9]+$/i', $name) === 1
            || preg_match('/^[A-Z0-9][A-Z0-9-]{2,}$/', $name) === 1 && strtoupper($name) === $name && preg_match('/\d/', $name) === 1;
    }

    /** "Windows 11", "Windos 11", "Win10": the system's name, not the person's. */
    private static function looksLikeWindows(string $name): bool
    {
        return preg_match('/^win[a-z]*\s*\d*(\s*pc)?$/i', $name) === 1;
    }
}
