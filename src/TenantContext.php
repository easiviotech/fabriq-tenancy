<?php

declare(strict_types=1);

namespace SwooleFabric\Tenancy;

/**
 * Value object representing a resolved tenant.
 *
 * Immutable after construction. Carried through every execution path
 * (HTTP, WS, job, consumer) so downstream code always knows which
 * tenant it is operating on.
 */
final class TenantContext
{
    /**
     * @param string $id          UUID of the tenant
     * @param string $slug        URL-safe slug (unique)
     * @param string $name        Display name
     * @param string $plan        Billing plan (free, pro, enterprise…)
     * @param string $status      active | suspended | deleted
     * @param string|null $domain Custom domain (nullable)
     * @param array<string, mixed> $config  Tenant-specific config overrides
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $plan = 'free',
        public readonly string $status = 'active',
        public readonly ?string $domain = null,
        public readonly array $config = [],
    ) {}

    /**
     * Build from a database row / associative array.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $configJson = $row['config_json'] ?? $row['config'] ?? null;
        $config = [];

        if (is_string($configJson) && $configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        } elseif (is_array($configJson)) {
            $config = $configJson;
        }

        return new self(
            id: (string) ($row['id'] ?? ''),
            slug: (string) ($row['slug'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            plan: (string) ($row['plan'] ?? 'free'),
            status: (string) ($row['status'] ?? 'active'),
            domain: isset($row['domain']) ? (string) $row['domain'] : null,
            config: $config,
        );
    }

    /**
     * Serialize to array (for caching / logging).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'plan' => $this->plan,
            'status' => $this->status,
            'domain' => $this->domain,
            'config' => $this->config,
        ];
    }

    /**
     * Check if the tenant is usable (active).
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Read a tenant-specific config value with dot-notation.
     */
    public function configValue(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

