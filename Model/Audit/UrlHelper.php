<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class UrlHelper
{
    public const RESOURCE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
        'pdf', 'css', 'js', 'mjs', 'zip', 'gz', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm', 'mp3',
    ];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp'];

    public static function isHttp(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }
        if (str_starts_with($href, '//')) {
            return true;
        }
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $href, $m) !== 1) {
            return true;
        }

        return in_array(strtolower($m[1]), ['http', 'https'], true);
    }

    public static function isTemplatePlaceholder(string $href): bool
    {
        return preg_match('~\{\{|\}\}|\$\{|<%|%>|#\{|\[\[|\]\]~', $href) === 1;
    }

    public static function isRelative(string $href): bool
    {
        $href = trim($href);

        return $href !== ''
            && !str_starts_with($href, '/')
            && !str_starts_with($href, '#')
            && !str_starts_with($href, '?')
            && preg_match('~^[a-z][a-z0-9+.\-]*:~i', $href) !== 1;
    }

    public static function resolve(string $href, string $base): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!self::isHttp($href) || self::isTemplatePlaceholder($href)) {
            return null;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['host'])) {
            return null;
        }
        $scheme = strtolower($baseParts['scheme'] ?? 'https');

        if (preg_match('~^https?://~i', $href) === 1) {
            return self::stripFragment($href);
        }
        if (str_starts_with($href, '//')) {
            return self::stripFragment($scheme . ':' . $href);
        }

        $authority = $scheme . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        $basePath  = $baseParts['path'] ?? '/';

        if (str_starts_with($href, '?')) {
            return self::stripFragment($authority . $basePath . $href);
        }

        $query = '';
        $qPos  = strpos($href, '?');
        if ($qPos !== false) {
            $query = substr($href, $qPos);
            $href  = substr($href, 0, $qPos);
        }

        $path = str_starts_with($href, '/')
            ? $href
            : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $href;

        return self::stripFragment($authority . self::removeDotSegments($path) . $query);
    }

    public static function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $output   = [];
        $last     = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '.') {
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);

        return str_starts_with($result, '/') ? $result : '/' . $result;
    }

    public static function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function query(string $url): string
    {
        return (string) parse_url($url, PHP_URL_QUERY);
    }

    public static function extension(string $url): string
    {
        $path = self::path($url);
        $dot  = strrpos($path, '.');
        if ($dot === false || strrpos($path, '/') > $dot) {
            return '';
        }

        return strtolower(substr($path, $dot + 1));
    }

    public static function isResource(string $url): bool
    {
        return in_array(self::extension($url), self::RESOURCE_EXTENSIONS, true);
    }

    public static function isImage(string $url): bool
    {
        return in_array(self::extension($url), self::IMAGE_EXTENSIONS, true);
    }

    public static function sameHost(string $url, string $host): bool
    {
        return $host !== '' && strcasecmp(self::host($url), $host) === 0;
    }

    public static function normalize(string $url): string
    {
        $url   = self::stripFragment(trim($url));
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host']) . $port
            . ($parts['path'] ?? '/') . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    public static function trailingSlashTwin(string $url): ?string
    {
        $parts = parse_url(self::normalize($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if ($path === '/' || self::extension($url) !== '') {
            return null;
        }
        $root = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $twin = str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/';

        return $root . $twin . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }
}
