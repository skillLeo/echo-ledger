<span @class(['el-status', 'el-status-'.$status['tone'], 'el-status-spin' => $status['spin']])>
    @svg($status['icon'], ['aria-hidden' => 'true'])
    {{ $status['label'] }}
</span>
