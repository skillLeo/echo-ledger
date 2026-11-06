<?php

declare(strict_types=1);

namespace App\Services\Screens;

use RuntimeException;

/** The picture passed the type check but GD could not decode it or write its thumbnail. */
final class UnreadablePicture extends RuntimeException {}
