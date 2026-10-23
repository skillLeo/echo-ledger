<?php

declare(strict_types=1);

namespace App\Stt\Exceptions;

/** The provider reported a permanent failure for this job (do not retry). */
final class ProviderJobFailed extends SttException {}
