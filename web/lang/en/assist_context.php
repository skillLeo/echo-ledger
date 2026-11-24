<?php

declare(strict_types=1);

/*
 * Live Assist's words that need their context (lead decision on ur.json, 2026-11-26): a short word in ur.json carries
 * its most general meaning, so where a page means something narrower it reads a key of this group. English stays the
 * same word, byte for byte. Named *_context, never a plain word: a group file named like a key (assist, workspace)
 * would answer __('Workspace') with the whole group on a case-insensitive disk.
 */
return [
    // The sessions table's column: which app it ran on (Computer, Phone, …).
    'col_platform' => 'On',
    // The relay's state on Live Assist now.
    'relay_up' => 'Up',
    'relay_down' => 'Down',
    // A suggestion of the report that the person used.
    'suggestion_used' => 'Used',
];
