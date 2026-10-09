<?php
declare(strict_types=1);

/**
 * Resolves the public agent slug to an internal agent record (Section 36).
 *
 * The slug is a lookup key only. Nothing in the request is ever treated as
 * an internal agent_id, and the resolved record is re-validated on every
 * request rather than cached across requests.
 */
final class AgentResolver
{
    /**
     * @return array|null the agent row, or null when it cannot be used publicly
     */
    public static function bySlug(?string $slug): ?array
    {
        $slug = Validator::slug($slug);
        if ($slug === null) {
            return null;
        }
        $agent = db()->one(
            "SELECT * FROM agents WHERE slug = ? AND status = 'active' LIMIT 1",
            [$slug]
        );
        return $agent ?: null;
    }

    /** Admin-side lookup: includes disabled agents, excludes deleted ones. */
    public static function byIdForAdmin(int $id): ?array
    {
        return db()->one(
            "SELECT * FROM agents WHERE id = ? AND status <> 'deleted' LIMIT 1",
            [$id]
        ) ?: null;
    }

    /**
     * @return string[] hostnames this agent's widget may be embedded on
     */
    public static function allowedDomains(array $agent): array
    {
        $raw  = (string)($agent['allowed_domains'] ?? '');
        $out  = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $host = Validator::hostname($line);
            if ($host !== null) {
                $out[] = $host;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Domain validation (Section 40).
     *
     * This is a security control, not authentication — it is one of several
     * layers, never relied on alone.
     *
     * @return array{allowed:bool,origin:?string,reason:?string}
     */
    public static function validateOrigin(array $agent): array
    {
        $domains = self::allowedDomains($agent);

        // No list configured means the agent has not been locked down yet.
        // Fail closed for a live agent rather than accepting any origin.
        if (!$domains) {
            return ['allowed' => false, 'origin' => null, 'reason' => 'no_allowed_domains_configured'];
        }

        $origin = Security::originHost();
        if ($origin !== null) {
            return self::matches($origin, $domains)
                ? ['allowed' => true, 'origin' => $origin, 'reason' => null]
                : ['allowed' => false, 'origin' => $origin, 'reason' => 'origin_not_allowed'];
        }

        // Referer is supplementary only — accepted when Origin is absent
        // (some browsers omit Origin on same-site navigations), never preferred.
        $referer = Security::refererHost();
        if ($referer !== null) {
            return self::matches($referer, $domains)
                ? ['allowed' => true, 'origin' => $referer, 'reason' => 'referer_fallback']
                : ['allowed' => false, 'origin' => $referer, 'reason' => 'referer_not_allowed'];
        }

        return ['allowed' => false, 'origin' => null, 'reason' => 'no_origin_header'];
    }

    /** Hostname match supporting a leading wildcard label. */
    public static function matches(string $host, array $domains): bool
    {
        $host = strtolower($host);
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($domain === $host) {
                return true;
            }
            if (str_starts_with($domain, '*.')) {
                $base = substr($domain, 2);
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return true;
                }
                continue;
            }
            // Bare domain also covers its subdomains (example.com covers www.example.com)
            if (str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The Access-Control-Allow-Origin value to echo back, or null.
     * Only an exact, already-validated origin is echoed — never "*".
     */
    public static function corsOrigin(array $agent): ?string
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return null;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        return self::matches(strtolower($host), self::allowedDomains($agent)) ? $origin : null;
    }

    /** Domains whose links the agent may render as clickable (Section 29). */
    public static function approvedLinkDomains(array $agent): array
    {
        $domains = self::allowedDomains($agent);
        $website = (string)($agent['website_url'] ?? '');
        if ($website !== '') {
            $host = parse_url($website, PHP_URL_HOST);
            if (is_string($host)) {
                $domains[] = strtolower($host);
            }
        }
        return array_values(array_unique($domains));
    }
}
