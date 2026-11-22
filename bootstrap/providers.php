<?php

use App\Modules\Ppr\PprServiceProvider;
use App\Modules\Sourcing\SourcingServiceProvider;
use App\Modules\Srm\SrmServiceProvider;
use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CompanyServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MonitoringServiceProvider;

return [
    AppServiceProvider::class,
    CompanyServiceProvider::class,
    ApiServiceProvider::class,
    FortifyServiceProvider::class,
    MonitoringServiceProvider::class,
    SrmServiceProvider::class,
    PprServiceProvider::class,
    // Connects the two modules above, so it comes after them.
    SourcingServiceProvider::class,
];
