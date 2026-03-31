<?php

declare(strict_types=1);

namespace App\Approov\State;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ApproovStateStore
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config
    ) {
    }

    public function state(): ApproovState
    {
        return new ApproovState(
            $this->cache->get($this->approovEnabledKey(), true),
            $this->cache->get($this->tokenBindingEnabledKey(), true)
        );
    }

    public function enableApproov(): ApproovState
    {
        $this->cache->forever($this->approovEnabledKey(), true);
        $this->cache->forever($this->tokenBindingEnabledKey(), true);

        return $this->state();
    }

    public function disableApproov(): ApproovState
    {
        $this->cache->forever($this->approovEnabledKey(), false);
        $this->cache->forever($this->tokenBindingEnabledKey(), false);

        return $this->state();
    }

    public function enableTokenBinding(): ApproovState
    {
        $this->cache->forever($this->tokenBindingEnabledKey(), true);

        return $this->state();
    }

    public function disableTokenBinding(): ApproovState
    {
        $this->cache->forever($this->tokenBindingEnabledKey(), false);

        return $this->state();
    }

    private function approovEnabledKey(): string
    {
        return (string) $this->config->get('approov.cache_keys.approov_enabled', 'approov_enabled');
    }

    private function tokenBindingEnabledKey(): string
    {
        return (string) $this->config->get('approov.cache_keys.token_binding_enabled', 'approov_token_binding_enabled');
    }
}
