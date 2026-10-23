<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Assembling = 'assembling';
    case Ready = 'ready';
    case Failed = 'failed';
}
