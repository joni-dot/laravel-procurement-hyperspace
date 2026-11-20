<?php

use App\Modules\Srm\SrmServiceProvider;
use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    ApiServiceProvider::class,
    FortifyServiceProvider::class,
    SrmServiceProvider::class,
];
