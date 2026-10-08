<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWhatsAppClient();
    }

    /**
     * Register the HTTP client used to call the WhatsApp Cloud API.
     */
    protected function configureWhatsAppClient(): void
    {
        Http::macro('whatsapp', fn (): PendingRequest => Http::baseUrl('https://graph.facebook.com/'.config('services.whatsapp.graph_version'))
            ->withToken(config('services.whatsapp.access_token'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
