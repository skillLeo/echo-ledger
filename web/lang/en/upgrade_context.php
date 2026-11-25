<?php

declare(strict_types=1);

/*
 * Upgrade words whose reading depends on where they stand. lang/ur.json keeps a short word's general meaning; the
 * reading for this place is here, by a context key, with the same English.
 */

return [
    // The Upgrade page's own name (its title and heading): the noun. lang/ur.json's "Upgrade" is the button's verb.
    'page_title' => 'Upgrade',
    // Under a receipt already sent, with the day it was sent ("Sent 17 Nov").
    'tile_sent' => 'Sent :date',
    // The tag of a request the person cancelled, beside Approved and Waiting: a state, not a sentence.
    'tag_cancelled' => 'Cancelled',
];
