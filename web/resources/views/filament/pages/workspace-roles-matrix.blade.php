{{-- Workspace → What each role may do (since v1.6, P2.5): the four roles of a workspace; nobody sees another person's recordings unless shared. --}}
@php
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $head = 'padding:.5rem .75rem;text-align:center;font-weight:600';
    $roles = ['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];
@endphp
<x-filament::section heading="What each role may do" description="Each person's recordings and projects are theirs alone, owners and admins included, until they share them." collapsible collapsed data-workspace-roles>
    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="padding:.5rem .75rem;text-align:left;opacity:.7;font-weight:500">May</th>
                    @foreach ($roles as $key => $label)
                        <th style="{{ $head }}">{{ $label }}@if ($mine === $key)<div style="font-weight:400;font-size:.75rem;opacity:.7">you</div>@endif</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $label => $values)
                    <tr>
                        <td style="{{ $cell }}">{{ $label }}</td>
                        @foreach ($values as $value)
                            <td style="{{ $cell }}text-align:center">
                                @if ($value === 'yes')
                                    <input type="checkbox" checked disabled aria-label="{{ $label }}: yes" style="width:1.1rem;height:1.1rem">
                                @elseif ($value === 'no')
                                    <input type="checkbox" disabled aria-label="{{ $label }}: no" style="width:1.1rem;height:1.1rem">
                                @else
                                    <span style="font-size:.8rem;opacity:.8">{{ $value }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
