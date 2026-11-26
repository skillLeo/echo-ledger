<?php

declare(strict_types=1);

/*
 * Words of the Library's side pages (Search, Recently deleted) whose meaning depends on where they stand (the team's
 * rule for lang/: ur.json is one list for the whole console, so a word that means something else here gets a key of its
 * own in an <area>_context.php group). The English is the console's words as they were.
 */
return [

    // Search's heading: the page's name, not the command to search.
    'search_title' => 'Search',

    // Search's date range: the first and the last day (ur.json's "From" is a source).
    'search_from' => 'From',
    'search_to' => 'To',

    // Search's pager: the page of newer results, and of older ones.
    'search_newer' => 'Newer',
    'search_older' => 'Older',

    // Recently deleted's columns: when the recording was made, was deleted, and will be erased.
    'col_recorded' => 'Recorded',
    'col_deleted' => 'Deleted',
    'col_erased' => 'Erased',

];
