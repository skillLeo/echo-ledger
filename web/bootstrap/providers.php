<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConnectorServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    AppServiceProvider::class,
    ConnectorServiceProvider::class,
    AppPanelProvider::class,
    AdminPanelProvider::class,
];
