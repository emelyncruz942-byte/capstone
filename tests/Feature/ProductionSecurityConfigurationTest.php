<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class ProductionSecurityConfigurationTest extends TestCase
{
    public function test_production_rejects_a_process_local_rate_limiter(): void
    {
        $this->configureProductionSecurity();
        config(['cache.limiter' => 'array']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Production CACHE_LIMITER must use a shared');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_rejects_wildcard_or_path_based_cors_origins(): void
    {
        $this->configureProductionSecurity();
        config(['cors.allowed_origins' => ['https://*.example.test/path']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Production CORS_ALLOWED_ORIGINS may contain only exact HTTPS origins');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_accepts_an_exact_https_origin_and_shared_limiter(): void
    {
        $this->configureProductionSecurity();

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame(['https://mathmetaverse.space'], config('cors.allowed_origins'));
    }

    private function configureProductionSecurity(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://mathmetaverse.space',
            'app.canonical_url' => 'https://mathmetaverse.space',
            'session.encrypt' => true,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cache.limiter' => 'shared-security-test',
            'cache.stores.shared-security-test' => ['driver' => 'redis', 'connection' => 'cache'],
            'cors.allowed_origins' => ['https://mathmetaverse.space'],
            'cors.allowed_origins_patterns' => [],
            'cors.supports_credentials' => false,
        ]);
    }
}
