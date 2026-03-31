<?php

declare(strict_types=1);

namespace App\Approov\State;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ApproovStateStore
{
    /**
     * Creates the state store with cache and configuration dependencies.
     *
     * @param  CacheRepository  $cache  The cache repository used to persist state flags.
     * @param  ConfigRepository  $config  The configuration repository used to resolve cache key names.
     * @return void
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config
    ) {
    }

    /**
     * Reads the current Approov and token-binding flags from cache.
     *
     * @return ApproovState
     */
    public function state(): ApproovState
    {
        return new ApproovState(
            $this->cache->get($this->approovEnabledKey(), true),
            $this->cache->get($this->tokenBindingEnabledKey(), true)
        );
    }

    /**
     * Persists enabled values for both Approov verification and token binding.
     *
     * @return ApproovState
     */
    public function enableApproov(): ApproovState
    {
        $this->cache->forever($this->approovEnabledKey(), true);
        $this->cache->forever($this->tokenBindingEnabledKey(), true);

        return $this->state();
    }

    /**
     * Persists disabled values for both Approov verification and token binding.
     *
     * @return ApproovState
     */
    public function disableApproov(): ApproovState
    {
        $this->cache->forever($this->approovEnabledKey(), false);
        $this->cache->forever($this->tokenBindingEnabledKey(), false);

        return $this->state();
    }

    /**
     * Persists an enabled value for token binding.
     *
     * @return ApproovState
     */
    public function enableTokenBinding(): ApproovState
    {
        $this->cache->forever($this->tokenBindingEnabledKey(), true);

        return $this->state();
    }

    /**
     * Persists a disabled value for token binding.
     *
     * @return ApproovState
     */
    public function disableTokenBinding(): ApproovState
    {
        $this->cache->forever($this->tokenBindingEnabledKey(), false);

        return $this->state();
    }

    /**
     * Returns the cache key used to store the Approov enabled flag.
     *
     * @return string
     */
    private function approovEnabledKey(): string
    {
        return (string) $this->config->get('approov.cache_keys.approov_enabled', 'approov_enabled');
    }

    /**
     * Returns the cache key used to store the token-binding enabled flag.
     *
     * @return string
     */
    private function tokenBindingEnabledKey(): string
    {
        return (string) $this->config->get('approov.cache_keys.token_binding_enabled', 'approov_token_binding_enabled');
    }
}
