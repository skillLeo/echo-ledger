<?php

declare(strict_types=1);

/*
 * A new record's page in Urdu (W-213). Filament's own Urdu has these lines under resources/create-record.php, not
 * the resources/pages/create-record.php the page reads, so the page said "Create", "Create & create another" and
 * "Cancel" in English.
 */
return [
    'title' => ':label بنائیں',
    'breadcrumb' => 'نیا بنائیں',
    'form' => [
        'actions' => [
            'cancel' => ['label' => 'منسوخ کریں'],
            'create' => ['label' => 'بنائیں'],
            'create_another' => ['label' => 'بنائیں اور ایک اور بنائیں'],
        ],
    ],
    'notifications' => [
        'created' => ['title' => 'بن گیا'],
    ],
];
