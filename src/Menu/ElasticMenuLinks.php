<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Menu;

/** Builds admin links from configuration only; never connects to the cluster. */
final class ElasticMenuLinks
{
    public static function safeUrl(?string $url): ?string
    {
        if (!$url || !is_array($parts = parse_url($url)) || !isset($parts['host'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        // DSN credentials, API keys and fragments never belong in menu HTML.
        return rtrim($parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? ''), '/');
    }

    /** @return array<string, string> */
    public static function endpoints(array $adapters): array
    {
        $result = [];
        foreach ($adapters as $name => $config) {
            $parts = parse_url($config['dsn'] ?? '');
            if (!is_array($parts) || !isset($parts['host'])
                || !in_array($parts['scheme'] ?? '', ['elasticsearch', 'elastic', 'elasticsearch+https'], true)) {
                continue;
            }
            $result[$name] = ($parts['scheme'] === 'elasticsearch+https' ? 'https' : 'http')
                .'://'.$parts['host'].':'.($parts['port'] ?? 9200);
        }

        return $result;
    }

    public static function kibana(?string $configured, array $endpoints, bool $debug): ?string
    {
        if ($configured !== null) {
            return self::safeUrl($configured);
        }
        if (!$debug || $endpoints === []) {
            return null;
        }
        foreach ($endpoints as $endpoint) {
            if (!in_array(parse_url($endpoint, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true)) {
                return null;
            }
        }

        return 'http://localhost:5601';
    }
}
