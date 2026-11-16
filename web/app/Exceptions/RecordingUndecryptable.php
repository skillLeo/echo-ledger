<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A stored recording read in full that does not decrypt: not an EchoLedger file, cut short or with bytes after its end,
 * or changed (since v1.8.1). Reading it again gives the same answer, unlike a dropped request or a full disk.
 */
final class RecordingUndecryptable extends RuntimeException {}
