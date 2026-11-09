{{-- Settings › Profile, under the name and email: the workspace (team workspaces only). --}}
@if ($team)
    <x-el.section>
        <x-el.row setting="profile.workspace" title="Workspace" :value="$workspace" />
    </x-el.section>
@endif
