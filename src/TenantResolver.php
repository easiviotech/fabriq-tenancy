<?php

declare(strict_types=1);

namespace Fabriq\Tenancy;

use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Fabriq\Kernel\Context;

/**
 * TenantResolver — resolves the current tenant from an HTTP request.
 *
 * Resolution chain (configurable priority):
 *   1. Host header (subdomain or custom domain)
 *   2. X-Tenant header (explicit slug)
 *   3. JWT token claim (tenant_id from decoded token)
 *
 * Requires a lookup callable that maps a slug/id/domain to a TenantContext.
 */
final class TenantResolver
{
    /** @var list<string> Resolution strategy order */
    private array $chain;

    /** @var callable(string, string): ?TenantContext  fn(type, value) → TenantContext|null */
    private $lookup;

    /**
     * @param list<string> $chain  Ordered resolution strategies: 'host', 'header', 'token'
     * @param callable(string, string): ?TenantContext $lookup  Lookup function
     */
    public function __construct(array $chain, callable $lookup)
    {
        $this->chain = $chain;
        $this->lookup = $lookup;
    }

    /**
     * Resolve tenant from a Swoole HTTP request.
     *
     * Tries each strategy in chain order. Returns on first match.
     *
     * @throws RuntimeException if no tenant could be resolved
     */
    public function resolve(SwooleRequest $request): TenantContext
    {
        foreach ($this->chain as $strategy) {
            $tenant = match ($strategy) {
                'host'   => $this->resolveFromHost($request),
                'header' => $this->resolveFromHeader($request),
                'token'  => $this->resolveFromToken(),
                default  => null,
            };

            if ($tenant !== null) {
                if (!$tenant->isActive()) {
                    throw new RuntimeException("Tenant [{$tenant->slug}] is not active (status: {$tenant->status})");
                }
                return $tenant;
            }
        }

        throw new RuntimeException('Unable to resolve tenant from request');
    }

    /**
     * Resolve from Host header — extracts subdomain or matches custom domain.
     */
    private function resolveFromHost(SwooleRequest $request): ?TenantContext
    {
        $host = $request->header['host'] ?? null;
        if ($host === null || $host === '') {
            return null;
        }

        // Strip port
        $host = explode(':', $host)[0];

        // Try as custom domain first
        $tenant = ($this->lookup)('domain', $host);
        if ($tenant !== null) {
            return $tenant;
        }

        // Try subdomain (first segment before first dot, if multi-segment)
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            return ($this->lookup)('slug', $subdomain);
        }

        return null;
    }

    /**
     * Resolve from X-Tenant header (slug value).
     */
    private function resolveFromHeader(SwooleRequest $request): ?TenantContext
    {
        $slug = $request->header['x-tenant'] ?? null;
        if ($slug === null || $slug === '') {
            return null;
        }

        return ($this->lookup)('slug', $slug);
    }

    /**
     * Resolve from token claims already set on Context (by AuthMiddleware).
     *
     * Expects Context::getExtra('tenant_id') to be set by a prior auth step.
     */
    private function resolveFromToken(): ?TenantContext
    {
        $tenantId = Context::getExtra('token_tenant_id');
        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return ($this->lookup)('id', (string) $tenantId);
    }
}

