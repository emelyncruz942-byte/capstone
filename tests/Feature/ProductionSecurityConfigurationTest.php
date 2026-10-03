<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Facade;
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
        $this->assertSame('rate-limiter-failover', config('cache.limiter'));
        $this->assertSame(
            ['shared-security-test', 'file'],
            config('cache.stores.rate-limiter-failover.stores')
        );
    }

    public function test_production_limiter_remains_available_when_the_shared_store_is_down(): void
    {
        $this->configureProductionSecurity();
        config([
            'cache.stores.shared-security-test.connection' => 'unavailable-security-test',
            'database.redis.unavailable-security-test' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 15,
                'timeout' => 0.05,
                'read_timeout' => 0.05,
                'max_retries' => 0,
            ],
        ]);

        (new AppServiceProvider($this->app))->boot();

        // AppServiceProvider registered the named limiters while the test
        // container still held its original array-backed singleton. Resolve a
        // fresh instance to exercise the production failover configuration.
        $this->app->forgetInstance(CacheRateLimiter::class);
        Facade::clearResolvedInstance(CacheRateLimiter::class);
        $limiter = $this->app->make(CacheRateLimiter::class);
        $key = 'production-failover-test-'.bin2hex(random_bytes(8));

        try {
            $this->assertSame(1, $limiter->hit($key, 60));
            $this->assertSame(1, $limiter->attempts($key));
        } finally {
            $limiter->clear($key);
        }
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
