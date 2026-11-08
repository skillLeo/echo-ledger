<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Stt\LanguageRouter;
use Illuminate\Database\Migrations\Migration;

/**
 * v1.5 Phase 1C: cheapest engine first per language. A stored "Engine per language" list that still equals the old
 * default was never chosen by anyone and moves to the new default; a list someone (or the Accuracy Lab) changed stays.
 * The lists it moved are kept in `routing.final_before_cheapest`, which `down()` puts back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = Setting::query()->where('key', 'routing.final')->first();
        if ($row === null || ! is_array($row->value)) {
            return;
        }
        $routes = $row->value;
        $moved = [];
        foreach (LanguageRouter::PREVIOUS_FINAL_ROUTES as $key => $old) {
            if (($routes[$key] ?? null) === $old && $old !== LanguageRouter::DEFAULT_FINAL_ROUTES[$key]) {
                $moved[$key] = $old;
                $routes[$key] = LanguageRouter::DEFAULT_FINAL_ROUTES[$key];
            }
        }
        if ($moved === []) {
            return;
        }
        $row->update(['value' => $routes]);
        Setting::query()->updateOrCreate(['key' => 'routing.final_before_cheapest'], ['value' => $moved]);
    }

    public function down(): void
    {
        $backup = Setting::query()->where('key', 'routing.final_before_cheapest')->first();
        $row = Setting::query()->where('key', 'routing.final')->first();
        if ($backup === null || $row === null || ! is_array($backup->value) || ! is_array($row->value)) {
            return;
        }
        $row->update(['value' => [...$row->value, ...$backup->value]]);
        $backup->delete();
    }
};
