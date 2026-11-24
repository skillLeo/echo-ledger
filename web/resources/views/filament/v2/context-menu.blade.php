{{--
    UX-22 (PLAN_V2_1_POLISH 3b): what a Library row's right-click menu needs, drawn inside the page so it follows it
    (ListCaptureSessions::menuData: the actions of the one action list in the console's language, what can run here,
    the person's labels and projects, the export formats and the menu's words), and its glyphs. Each action's glyph
    is the one the console draws it with: Pin is the row's star and Mark as important its flag.
    filament.v2.context-menu-script opens the menu (resources/js/context-menu-core.cjs decides what is in it).
--}}
<script type="application/json" data-v2-menu-data>@json($this->menuData())</script>
<div hidden data-v2-menu-icons>
    @foreach (['TranscriptIcon' => 'transcript', 'PinIcon' => 'star', 'StarIcon' => 'flag', 'MailIcon' => 'inbox', 'LabelIcon' => 'label', 'MoveIcon' => 'move',
        'RenameIcon' => 'rename', 'DownloadIcon' => 'download', 'TrashIcon' => 'trash', 'PlusIcon' => 'plus', 'ShareIcon' => 'share', 'LinkIcon' => 'link',
        'UsersIcon' => 'users', 'SendIcon' => 'chat', 'check' => 'check', 'more' => 'chevron-end'] as $icon => $glyph)
        <template data-icon="{{ $icon }}"><x-el.v2.icon :name="$glyph" /></template>
    @endforeach
</div>
