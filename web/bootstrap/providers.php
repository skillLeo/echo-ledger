<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConnectorServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    ConnectorServiceProvider::class,
    AdminPanelProvider::class,
];
