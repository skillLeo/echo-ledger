<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AccountExport;
use App\Services\Accounts\AccountExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Builds one "Export my data" ZIP (since v1.5). Once: a failure marks the export failed and the person asks again. */
final class MakeAccountExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The queue worker's own limit (--timeout=900). */
    public int $timeout = 900;

    public function __construct(public readonly string $exportId) {}

    public function handle(AccountExporter $exporter): void
    {
        $export = AccountExport::query()->find($this->exportId);
        if (! $export instanceof AccountExport || $export->status !== 'making') {
            return;
        }
        try {
            $exporter->build($export);
        } catch (Throwable $e) {
            $exporter->failed($export, $e);
        }
    }

    public function failed(Throwable $e): void
    {
        $export = AccountExport::query()->find($this->exportId);
        if ($export instanceof AccountExport && $export->status === 'making') {
            app(AccountExporter::class)->failed($export, $e);
        }
    }
}
