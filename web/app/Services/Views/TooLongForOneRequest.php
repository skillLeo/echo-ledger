<?php

declare(strict_types=1);

namespace App\Services\Views;

use RuntimeException;

/** The answer for a chunk of lines hit the output limit: the chunk is split in two and sent again. */
final class TooLongForOneRequest extends RuntimeException {}
