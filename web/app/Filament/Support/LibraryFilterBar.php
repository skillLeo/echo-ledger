<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\SourceApp;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Shares;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\User;
use App\Services\Library\LibraryFacets;
use App\Services\Library\LibraryFilters;
use App\Services\Library\SavedViews;
use App\Services\Lists\LabelLists;
use App\Services\SessionMarkers;
use App\Services\SettingsRepository;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;

/**
 * The words and places of the web Library's filters and views (contract 2.0.0-p, docs/PLAN_LIBRARY_FILTERS.md): each
 * section's and option's name in the console's language, the filter panel's sections, the pills of the filters that
 * are on, and the sidebar's Library places, My views and labels with their counts. The rules themselves are
 * LibraryFilters' and LibraryFacets'.
 */
final class LibraryFilterBar
{
    /** The panel shows a search box over a section with more options than this. */
    public const FIND_OVER = 8;

    /** The sections' names. */
    public static function sectionLabel(string $section): string
    {
        return match ($section) {
            'date' => __('Date'),
            'source' => __('Source'),
            'device' => __('Device'),
            'project' => __('Project'),
            'language' => __('Language'),
            'status' => __('Status'),
            'labels' => __('Labels'),
            'marked' => __('Marked'),
            'bookmarks' => __('Bookmarks'),
            default => $section,
        };
    }

    /**
     * An option's name; [$option] is the facets' option (it names projects and labels).
     *
     * @param  array<string, mixed>|null  $option
     */
    public static function optionLabel(string $section, string $value, ?array $option = null): string
    {
        return match ($section) {
            'date' => match ($value) {
                'today' => __('Today'),
                'last_7_days' => __('Last 7 days'),
                'this_month' => __('This month'),
                'custom' => __('Custom dates'),
                default => __('Any time'),
            },
            'source' => match ($value) {
                'imported' => __('Imported'),
                default => __((SourceApp::tryFrom($value) ?? SourceApp::Other)->getLabel()),
            },
            'device' => match ($value) {
                'phone' => __('Phone'),
                'desktop' => __('Desktop'),
                default => __('Web'),
            },
            'project' => $value === LibraryFilters::NO_PROJECT ? __('No project') : (is_string($option['name'] ?? null) ? $option['name'] : __('A project that is gone')),
            'language' => $value === LibraryFilters::UNKNOWN_LANGUAGE ? __('Unknown') : __(LanguageChoice::label($value)),
            'status' => match ($value) {
                'transcribed' => __('Transcribed'),
                // The glossary's names (docs/PLAN_V2_1_POLISH.md section 1); the values stay the API's. `processing`
                // holds uploads still on their way as well as transcripts being written, so it is named for both.
                'not_transcribed' => __('Audio only'),
                'processing' => __('In progress'),
                default => __('Failed'),
            },
            'labels' => is_string($option['name'] ?? null) ? $option['name'] : $value,
            'marked' => match ($value) {
                'pinned' => __('Pinned'),
                'important' => __('Important'),
                default => __('Keep forever'),
            },
            'bookmarks' => $value === LibraryFilters::ANY_BOOKMARK ? __('Any bookmark') : self::tagName($value),
            default => $value,
        };
    }

    /**
     * A bookmark tag (kept in lower case) as the settings spell it: a default tag in the console's language, an admin's
     * own as typed (a tag named like a lang group would come back from __() as an array).
     */
    private static function tagName(string $key): string
    {
        foreach (app(SettingsRepository::class)->markerTags() as $tag) {
            if (mb_strtolower($tag) === $key) {
                return in_array($tag, SessionMarkers::DEFAULT_TAGS, true) ? __($tag) : $tag;
            }
        }

        return mb_strtoupper(mb_substr($key, 0, 1)).mb_substr($key, 1);
    }

    /**
     * The filter panel: every section with its options, counts, what is ticked, is / is not and the custom dates.
     *
     * @param  array<string, array{op: string, values: list<string>, from?: string, to?: string}>  $filters
     * @param  array{sections: array<string, list<array<string, mixed>>>, states?: array<string, int>}  $facets
     * @return list<array{key: string, label: string, op: string, on: bool, values: list<string>, from: string|null, to: string|null, options: list<array<string, mixed>>}>
     */
    public static function panel(array $filters, array $facets): array
    {
        $sections = [];
        foreach (LibraryFilters::SECTIONS as $section) {
            $filter = $filters[$section] ?? null;
            $ticked = $filter['values'] ?? [];
            $options = [];
            if ($section === 'date') {
                $counts = collect($facets['sections']['date'])->pluck('count', 'value')->all();
                foreach (['any', 'today', 'last_7_days', 'this_month', 'custom'] as $value) {
                    $options[] = ['value' => $value, 'label' => self::optionLabel('date', $value), 'count' => $counts[$value] ?? null,
                        'on' => $ticked === [] ? $value === 'any' : in_array($value, $ticked, true)];
                }
            } else {
                foreach ($facets['sections'][$section] ?? [] as $option) {
                    $value = (string) $option['value'];
                    $options[] = ['value' => $value, 'label' => self::optionLabel($section, $value, $option), 'count' => (int) $option['count'],
                        'on' => in_array($value, $ticked, true), 'child' => ($option['parent_id'] ?? null) !== null,
                        ...($section === 'labels' ? self::dot($option['color'] ?? null) : []),
                        ...($section === 'status' && $value === 'processing' ? ['parts' => self::processingParts($facets['states'] ?? [], (int) $option['count'])] : [])];
                }
            }
            $sections[] = ['key' => $section, 'label' => self::sectionLabel($section), 'op' => $filter['op'] ?? 'is', 'on' => $filter !== null,
                'values' => $ticked, 'from' => $filter['from'] ?? null, 'to' => $filter['to'] ?? null, 'options' => $options];
        }

        return $sections;
    }

    /**
     * What In progress (Status `processing`) holds, from the glossary's states (contract 2.0.0-r, BE-05): "2 transcribing ·
     * 1 waiting to upload". The filter keeps its one value; its parts come from the facets' `states`, so they are given
     * only while they are that option's recordings (they add up to its count: the Status filter keeps `processing`).
     *
     * @param  array<string, int>  $states
     */
    public static function processingParts(array $states, int $count): ?string
    {
        $transcribing = (int) ($states['transcribing'] ?? 0);
        $waiting = (int) ($states['waiting_to_upload'] ?? 0);
        if ($count === 0 || $transcribing + $waiting !== $count) {
            return null;
        }
        $parts = array_filter([
            $transcribing > 0 ? trans_choice('1 transcribing|:count transcribing', $transcribing) : null,
            $waiting > 0 ? trans_choice('1 waiting to upload|:count waiting to upload', $waiting) : null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * The pills of the filters that are on, in the panel's order: "Source: WhatsApp, Zoom".
     *
     * @param  array<string, array{op: string, values: list<string>, from?: string, to?: string}>  $filters
     * @param  array{sections: array<string, list<array<string, mixed>>>}  $facets
     * @return list<array{section: string, label: string, op: string, text: string, values: list<array{value: string, label: string}>}>
     */
    public static function pills(array $filters, array $facets): array
    {
        $pills = [];
        foreach ($filters as $section => $filter) {
            $options = collect($facets['sections'][$section] ?? [])->keyBy('value');
            $values = array_map(fn (string $v): array => ['value' => $v, 'label' => self::optionLabel($section, $v, $options[$v] ?? null)], $filter['values']);
            $text = implode(', ', array_column($values, 'label'));
            if ($section === 'date' && ($filter['values'][0] ?? null) === 'custom') {
                $text = trim(($filter['from'] ?? '…').' – '.($filter['to'] ?? '…'));
            }
            $pills[] = ['section' => $section, 'label' => self::sectionLabel($section), 'op' => $filter['op'], 'text' => $text, 'values' => $values];
        }

        return $pills;
    }

    /**
     * A label's colour as the rows draw it (V2RecordingRow::dotLook): one of the eight by its class, another as its own.
     *
     * @return array{dot: string, own: string|null}
     */
    public static function dot(?string $color): array
    {
        $look = V2RecordingRow::dotLook($color);

        return ['dot' => $look['class'], 'own' => $look['own']];
    }

    /**
     * The Library's places by key, in the console's language: All recordings first (docs/PLAN_NAV_3_LEVELS.md section 0;
     * nothing is called the Inbox), then Needs you, Unread, Important and Audio only. Since v2.1 Not transcribed is Audio
     * only (docs/PLAN_V2_1_POLISH.md section 1): one name and one definition, the recordings whose rows say Audio only.
     *
     * @return array<string, string>
     */
    public static function placeNames(): array
    {
        return ['all' => __('All recordings'), 'needs_you' => __('Needs you'), 'unread' => __('Unread'), 'important' => __('Important'),
            'audio_only' => __('Audio only')];
    }

    /**
     * The sidebar's Library: the places with their counts, the person's saved views and labels. [$here] is the address
     * the page was opened at (the sidebar draws again on its own requests), to mark where you are.
     *
     * @return array{places: list<array<string, mixed>>, shared: list<array<string, mixed>>, views: list<array<string, mixed>>, labels: list<array<string, mixed>>, trash: array<string, mixed>|null, onLibrary: bool}
     */
    public static function sidebar(?string $here = null): array
    {
        $user = auth()->user();
        $workspaceId = CurrentWorkspace::id();
        if (! $user instanceof User || $workspaceId === null || ! CaptureSessionResource::canAccess()) {
            return ['places' => [], 'shared' => [], 'views' => [], 'labels' => [], 'trash' => null, 'onLibrary' => false];
        }
        $here ??= request()->getRequestUri();
        $path = (string) parse_url($here, PHP_URL_PATH);
        parse_str((string) parse_url($here, PHP_URL_QUERY), $query);
        $library = CaptureSessionResource::getUrl('index');
        $onLibrary = rtrim($path, '/') === rtrim((string) parse_url($library, PHP_URL_PATH), '/');
        $tab = is_string($query['tab'] ?? null) ? $query['tab'] : 'all';
        // Old links to Not transcribed (and the older Untranscribed) open Audio only.
        $tab = in_array($tab, ['not_transcribed', 'untranscribed'], true) ? 'audio_only' : $tab;
        $plain = $onLibrary && empty($query['f']) && empty($query['v']) && empty($query['search']);
        $facets = app(LibraryFacets::class);
        $counts = $facets->libraryCounts($workspaceId, $user->id);

        $places = [];
        $icons = ['all' => 'library', 'needs_you' => 'alert', 'unread' => 'inbox', 'important' => 'flag', 'audio_only' => 'mic'];
        $names = self::placeNames();
        foreach (array_keys($names) as $place) {
            // Audio only counts what its rows say (the engine's `not_transcribed`, LibraryFilters::state).
            $count = match ($place) {
                'all' => 0,
                'audio_only' => $counts['not_transcribed'],
                default => $counts[$place],
            };
            $places[] = ['key' => $place, 'label' => $names[$place], 'icon' => $icons[$place], 'url' => $place === 'all' ? $library : $library.'?tab='.$place,
                'count' => $count, 'strong' => in_array($place, ['needs_you', 'unread'], true),
                'active' => $plain && ($place === 'all' ? in_array($tab, ['all', ''], true) : $tab === $place)];
        }
        $shared = [];
        foreach ([[SharedWithMe::class, 'shared', __('Shared with me')], [Shares::class, 'links', __('My links')], [RequestLinksPage::class, 'requests', __('Request links')]] as [$page, $key, $label]) {
            if ($page::canAccess()) {
                $url = $page::getUrl();
                $shared[] = ['key' => $key, 'label' => $label, 'url' => $url, 'count' => $key === 'shared' ? app(Teammates::class)->sharedCount($user) : 0,
                    'active' => rtrim($path, '/') === rtrim((string) parse_url($url, PHP_URL_PATH), '/')];
            }
        }

        $views = [];
        $openView = $onLibrary && is_string($query['v'] ?? null) ? $query['v'] : null;
        foreach (app(SavedViews::class)->index($workspaceId, $user->id) as $view) {
            $filters = LibraryFilters::read(json_decode((string) json_encode($view['filters']), true));
            $views[] = ['id' => $view['id'], 'label' => $view['name'], 'url' => $library.'?v='.$view['id'], 'active' => $openView === $view['id'],
                'count' => $facets->total($workspaceId, $user->id, $filters, $view['search'])];
        }

        $labelFacets = $facets->facets($workspaceId, $user->id, [])['sections']['labels'];
        $onlyLabel = $onLibrary && is_array($query['f'] ?? null) && array_keys($query['f']) === ['labels'] ? ($query['f']['labels']['values'] ?? []) : [];
        $labels = [];
        foreach ($labelFacets as $option) {
            if (LabelLists::reserved((string) $option['value'])) {
                continue;
            }
            $labels[] = ['key' => $option['value'], 'label' => (string) ($option['name'] ?? $option['value']), 'count' => (int) $option['count'],
                'url' => $library.'?'.http_build_query(['f' => ['labels' => ['op' => 'is', 'values' => [$option['value']]]]]),
                'active' => is_array($onlyLabel) && $onlyLabel === [$option['value']], ...self::dot($option['color'] ?? null),
                // The colour's name when it is one of the eight (Colour › in the label's menu ticks it), else null.
                'colour' => V2RecordingRow::colourName($option['color'] ?? null)];
        }

        $trash = RecentlyDeleted::canAccess() ? ['label' => __('Trash'), 'url' => RecentlyDeleted::getUrl(),
            'active' => rtrim($path, '/') === rtrim((string) parse_url(RecentlyDeleted::getUrl(), PHP_URL_PATH), '/')] : null;

        // ⌘1…⌘9: the places first, then My views.
        $n = 1;
        foreach ($places as &$place) {
            $place['shortcut'] = $n++;
        }
        unset($place);
        foreach ($views as &$view) {
            $view['shortcut'] = $n <= 9 ? $n++ : null;
        }
        unset($view);

        return ['places' => $places, 'shared' => $shared, 'views' => $views, 'labels' => $labels, 'trash' => $trash, 'onLibrary' => $onLibrary];
    }
}
