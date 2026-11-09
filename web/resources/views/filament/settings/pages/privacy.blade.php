{{-- Settings › Privacy & data: recording consent, your export, support access and deleting the account. --}}
<x-el.section>
    <x-el.row setting="privacy.consent" title="Recording consent" :value="$consent" click="mountAction('consent')" />
    @if ($exportUrl)
        <x-el.row setting="privacy.export" title="Export my data" :value="$export" :href="$exportUrl" />
    @else
        <x-el.row setting="privacy.export" title="Export my data" :value="$export" click="mountAction('exportData')" />
    @endif
    <x-el.row setting="privacy.support_access" title="Support access" :value="$support" click="mountAction('supportAccess')" />
</x-el.section>
<p class="el-set-foot">Support never sees your recordings unless you let them look for a while.</p>

<x-el.section>
    @if ($deleting)
        <x-el.row setting="privacy.cancel_deletion" title="Cancel deletion" :value="$deleting" :href="$keepUrl" />
    @else
        <x-el.row setting="privacy.delete_account" title="Delete my account" :href="$deleteUrl" danger />
    @endif
</x-el.section>
