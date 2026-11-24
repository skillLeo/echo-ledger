<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The upload itself cannot be assembled: a part is missing or does not decrypt. Trying again cannot help, so the parts
 * are discarded and the device sends the file again (AssembleRecordingJob). Every other failure is the server's own
 * (an extension, the storage, the code) and keeps the parts for recordings:recover.
 */
final class AssemblyDataFault extends RuntimeException {}
