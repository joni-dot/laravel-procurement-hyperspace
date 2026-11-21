<?php

use App\Modules\Ppr\PprServiceProvider;
use App\Modules\Srm\SrmServiceProvider;
use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CompanyServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    CompanyServiceProvider::class,
    ApiServiceProvider::class,
    FortifyServiceProvider::class,
    SrmServiceProvider::class,
    PprServiceProvider::class,
];
