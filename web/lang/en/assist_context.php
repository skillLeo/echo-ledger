<?php

declare(strict_types=1);

/*
 * Live Assist's words read in their context. A short word in ur.json carries its most general meaning; where a page
 * means something narrower it reads a key of this group, whose English is the same word, byte for byte. Named
 * <area>_context, never a plain word: a group file named like a one-word key would answer __() for that key.
 */
return [
    // The sessions table's column: which app it ran on (Computer, Phone, …).
    'col_platform' => 'On',
    // The relay's state on Live Assist now.
    'relay_up' => 'Up',
    'relay_down' => 'Down',
    // A suggestion of the report that the person used.
    'suggestion_used' => 'Used',
    // A prep's field: the person the meeting is with.
    'with_person' => 'With',
    // Live Assist now's outages: when one began and ended, and its cause.
    'outage_from' => 'From',
    'outage_to' => 'To',
    'outage_why' => 'Why',
];
