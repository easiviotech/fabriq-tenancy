<?php

declare(strict_types=1);

namespace SwooleFabric\Tenancy;

/**
 * Per-worker in-memory cache for resolved tenant data.
 *
 * Avoids hitting the database on every request for the same tenant.
 * Entries expire after a configurable TTL.
 */
final class TenantConfigCache
{
    /** @var array<string, array{tenant: TenantContext, expires_at: float}> */
    private array $entries = [];

    /** TTL in seconds */
    private float $ttl;

    /** Maximum entries to prevent memory bloat */
    private int $maxEntries;

    public function __construct(float $ttl = 300.0, int $maxEntries = 1000)
    {
        $this->ttl = $ttl;
        $this->maxEntries = $maxEntries;
    }

    /**
     * Get a cached tenant by key (slug, id, or domain).
     */
    public function get(string $key): ?TenantContext
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];

        // Check expiry
        if (microtime(true) > $entry['expires_at']) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry['tenant'];
    }

    /**
     * Cache a tenant under one or more keys (id, slug, domain).
     */
    public function put(TenantContext $tenant): void
    {
        // Evict oldest entries if at capacity
        if (count($this->entries) >= $this->maxEntries) {
            $this->evictOldest();
        }

        $expiresAt = microtime(true) + $this->ttl;
        $entry = ['tenant' => $tenant, 'expires_at' => $expiresAt];

        // Cache under all possible lookup keys
        $this->entries['id:' . $tenant->id] = $entry;
        $this->entries['slug:' . $tenant->slug] = $entry;

        if ($tenant->domain !== null && $tenant->domain !== '') {
            $this->entries['domain:' . $tenant->domain] = $entry;
        }
    }

    /**
     * Invalidate all cached entries for a tenant.
     */
    public function invalidate(string $tenantId): void
    {
        $keysToRemove = [];

        foreach ($this->entries as $key => $entry) {
            if ($entry['tenant']->id === $tenantId) {
                $keysToRemove[] = $key;
            }
        }

        foreach ($keysToRemove as $key) {
            unset($this->entries[$key]);
        }
    }

    /**
     * Clear entire cache.
     */
    public function flush(): void
    {
        $this->entries = [];
    }

    /**
     * Return current cache size.
     */
    public function size(): int
    {
        return count($this->entries);
    }

    /**
     * Evict expired entries.
     */
    public function gc(): void
    {
        $now = microtime(true);
        foreach ($this->entries as $key => $entry) {
            if ($now > $entry['expires_at']) {
                unset($this->entries[$key]);
            }
        }
    }

    /**
     * Evict the oldest 10% of entries.
     */
    private function evictOldest(): void
    {
        // Sort by expires_at ascending (oldest first)
        uasort($this->entries, fn($a, $b) => $a['expires_at'] <=> $b['expires_at']);

        $toRemove = max(1, (int) (count($this->entries) * 0.1));
        $keys = array_keys($this->entries);

        for ($i = 0; $i < $toRemove && $i < count($keys); $i++) {
            unset($this->entries[$keys[$i]]);
        }
    }
}

