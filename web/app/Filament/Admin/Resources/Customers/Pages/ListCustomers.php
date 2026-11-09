<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Filament\Admin\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    public function getSubheading(): string
    {
        return 'Plans, usage and devices of every account. Recordings and transcripts stay private.';
    }
}
