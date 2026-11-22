<?php

declare(strict_types=1);

/*
 * The lists' sort, group and loading words in Urdu (W-212), merged over Filament's own `ur` lines: those say the sort
 * directions half in English ("صعودی (Ascending)") and leave the loading and result lines in English.
 */
return [
    'grouping' => [
        'fields' => [
            'direction' => [
                'options' => ['asc' => 'صعودی', 'desc' => 'نزولی'],
            ],
        ],
    ],
    'sorting' => [
        'fields' => [
            'direction' => [
                'options' => ['asc' => 'صعودی', 'desc' => 'نزولی'],
            ],
        ],
    ],
    'columns' => [
        'select' => [
            'loading_message' => 'لوڈ ہو رہا ہے…',
        ],
    ],
    'loading' => 'لوڈ ہو رہا ہے…',
    'result_count' => '{0} کوئی نتیجہ نہیں|{1} :count نتیجہ|[2,*] :count نتائج',
];
