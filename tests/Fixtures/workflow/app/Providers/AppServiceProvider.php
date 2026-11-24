<?php

namespace WorkflowFixture\App\Providers;

use Illuminate\Support\ServiceProvider;
use WorkflowFixture\App\Contracts\ShippingQuotes;
use WorkflowFixture\App\Services\FlatRateShipping;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingQuotes::class, FlatRateShipping::class);
    }
}
