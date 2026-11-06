<?php

declare(strict_types=1);

namespace App\Services\Connector;

use RuntimeException;

/** Something a tool answers in plain words ("No recording with that id."), as a tool error to the AI. */
final class ToolError extends RuntimeException {}
