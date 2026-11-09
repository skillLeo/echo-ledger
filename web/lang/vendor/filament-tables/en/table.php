<?php

declare(strict_types=1);

/*
 * The console's words for the selection bar (since v1.6, P5.2): "8 selected · Select all 20 · Clear". Merged over
 * Filament's own English lines; everything else stays Filament's.
 */
return [
    'selection_indicator' => [
        'selected_count' => '1 selected|:count selected',
        'actions' => [
            'select_all' => ['label' => 'Select all :count'],
            'deselect_all' => ['label' => 'Clear'],
        ],
    ],
];
