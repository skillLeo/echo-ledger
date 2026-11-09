{{-- Admin panel → Upgrade requests (since v1.7): a request's receipts. A picture opens full size in a new tab; a PDF
     always downloads. Files are served by the staff-only route, decrypted for that answer. --}}
@php
    /** @var \App\Models\UpgradeRequest $record */
    $proofs = $record->proofs;
    $kept = $proofs->whereNull('erased_at');
    $erased = $proofs->whereNotNull('erased_at')->first();
    $url = fn ($proof, string $variant): string => route('filament.admin.upgrade-proofs', ['proof' => $proof->id, 'variant' => $variant]);
@endphp
<div class="flex flex-wrap items-center gap-2 px-3 py-2">
    @forelse ($kept as $proof)
        @php
            $label = ($proof->locked_at !== null ? 'Sent' : 'Not sent yet').' · '.$proof->name.' · '.\Illuminate\Support\Number::fileSize($proof->bytes);
        @endphp
        @if ($proof->kind === \App\Models\UpgradeRequestProof::PDF)
            <a href="{{ $url($proof, 'full') }}" download title="{{ $label }}"
               class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5 @if ($proof->locked_at === null) opacity-60 @endif">
                PDF · {{ \Illuminate\Support\Number::fileSize($proof->bytes) }} · Download PDF
            </a>
        @else
            <a href="{{ $url($proof, 'full') }}" target="_blank" rel="noopener noreferrer" title="{{ $label }}">
                <img src="{{ $url($proof, 'thumb') }}" alt="Receipt {{ $loop->iteration }}" loading="lazy"
                     class="h-12 w-12 rounded-md border border-gray-200 object-cover dark:border-white/10 @if ($proof->locked_at === null) opacity-60 @endif">
            </a>
        @endif
    @empty
        <span class="text-sm text-gray-400 dark:text-gray-500">
            {{ $erased !== null ? 'Receipts deleted on '.$erased->erased_at?->format('M j, Y') : '—' }}
        </span>
    @endforelse
</div>
