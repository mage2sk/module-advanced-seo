<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class ResourceProbe
{
    public const MAX_PARALLEL    = 8;
    public const TIMEOUT_SECONDS = 5;
    public const RETRIES         = 1;
    public const HOST_DELAY_MS   = 100;
    public const BODY_LIMIT      = 262144;

    public const UNVERIFIABLE_HOSTS = [
        'linkedin.com',
        'x.com',
        'twitter.com',
        'facebook.com',
        'instagram.com',
        'medium.com',
        'wa.me',
        'whatsapp.com',
    ];

    public const UNVERIFIABLE_STATUSES = [403, 405, 429, 999];

    private const ENV_INTERNAL_HOST = 'PANTH_SEO_CRAWL_INTERNAL_HOST';

    private array $cache = [];

    private string $ownHost = '';

    private bool $verifyTls = true;

    private string $userAgent = 'PanthSeoAudit';

    private int $requests = 0;

    private array $pins = [];

    public function configure(string $ownHost, bool $verifyTls, string $userAgent): void
    {
        $this->ownHost   = strtolower($ownHost);
        $this->verifyTls = $verifyTls;
        $this->userAgent = $userAgent;
        $this->cache     = [];
        $this->requests  = 0;
        $this->pins      = [];
    }

    public function getRequestCount(): int
    {
        return $this->requests;
    }

    public static function isUnverifiableHost(string $host): bool
    {
        $host = strtolower($host);
        foreach (self::UNVERIFIABLE_HOSTS as $known) {
            if ($host === $known || str_ends_with($host, '.' . $known)) {
                return true;
            }
        }

        return false;
    }

    public function head(array $urls, bool $getFallback = true, bool $followRedirects = false): array
    {
        $results = $this->run($urls, 'HEAD', $followRedirects, 0);
        if (!$getFallback) {
            return $results;
        }
        $retry = [];
        foreach ($results as $url => $result) {
            if ($result->status === 0 || ($result->status >= 400 && !$result->unverifiable)) {
                $retry[] = $url;
            }
        }
        if ($retry !== []) {
            foreach ($this->run($retry, 'GET', $followRedirects, 1024) as $url => $result) {
                if ($result->status !== 0 || $results[$url]->status === 0) {
                    $results[$url] = $result;
                }
            }
        }

        return $results;
    }

    public function get(array $urls, bool $followRedirects = true, int $maxBytes = self::BODY_LIMIT): array
    {
        return $this->run($urls, 'GET', $followRedirects, $maxBytes);
    }

    public function fetchTimed(string $url): ProbeResult
    {
        $resolve = $this->pinHost($url, $error);
        if ($error !== '') {
            return new ProbeResult(url: $url, status: 0, error: $error);
        }
        $this->requests++;
        [$transport, $headers] = $this->transport($url);
        $captured = [];
        $ch = curl_init($transport);
        curl_setopt_array($ch, $this->options($url, $headers, 'GET', false, 0, $captured, $resolve));
        $body   = curl_exec($ch);
        $result = $this->toResult($url, $ch, is_string($body) ? $body : '', $captured);

        return $result;
    }

    private function run(array $urls, string $method, bool $follow, int $maxBytes): array
    {
        $results = [];
        $queue   = [];
        foreach (array_unique(array_filter($urls, 'is_string')) as $url) {
            $key = $method . '|' . (int) $follow . '|' . $maxBytes . '|' . $url;
            if (isset($this->cache[$key])) {
                $results[$url] = $this->cache[$key];
                continue;
            }
            $queue[] = $url;
        }
        if ($queue === []) {
            return $results;
        }

        $multi     = curl_multi_init();
        $active    = [];
        $attempts  = [];
        $lastStart = [];
        $captured  = [];

        while ($queue !== [] || $active !== []) {
            foreach ($queue as $index => $url) {
                if (count($active) >= self::MAX_PARALLEL) {
                    break;
                }
                $host = UrlHelper::host($url);
                if (isset($lastStart[$host]) && (microtime(true) - $lastStart[$host]) * 1000 < self::HOST_DELAY_MS) {
                    continue;
                }
                unset($queue[$index]);
                $resolve = $this->pinHost($url, $error);
                if ($error !== '') {
                    $results[$url] = new ProbeResult(url: $url, status: 0, error: $error);
                    $this->cache[$method . '|' . (int) $follow . '|' . $maxBytes . '|' . $url] = $results[$url];
                    continue;
                }
                $lastStart[$host] = microtime(true);
                [$transport, $headers] = $this->transport($url);
                $ch = curl_init($transport);
                $id = spl_object_id($ch);
                $captured[$id] = [];
                curl_setopt_array(
                    $ch,
                    $this->options($url, $headers, $method, $follow, $maxBytes, $captured[$id], $resolve)
                );
                curl_multi_add_handle($multi, $ch);
                $active[$id] = [$ch, $url];
                $attempts[$url] = ($attempts[$url] ?? 0) + 1;
                $this->requests++;
            }

            if ($active === []) {
                usleep(20000);
                continue;
            }

            curl_multi_exec($multi, $running);
            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                if (!isset($active[$id])) {
                    continue;
                }
                $url  = $active[$id][1];
                $body = $method === 'GET' ? (string) curl_multi_getcontent($ch) : '';
                $result = $this->toResult($url, $ch, $body, $captured[$id]);
                curl_multi_remove_handle($multi, $ch);
                unset($active[$id], $captured[$id]);

                if (($result->status === 0 || $result->status >= 500) && $attempts[$url] <= self::RETRIES) {
                    $queue[] = $url;
                    continue;
                }
                $results[$url] = $result;
                $this->cache[$method . '|' . (int) $follow . '|' . $maxBytes . '|' . $url] = $result;
            }
            if ($running > 0 && curl_multi_select($multi, 0.05) === -1) {
                usleep(1000);
            }
        }
        curl_multi_close($multi);

        return $results;
    }

    private function transport(string $url): array
    {
        $override = getenv(self::ENV_INTERNAL_HOST);
        if (!is_string($override) || $override === '' || !UrlHelper::sameHost($url, $this->ownHost)) {
            return [$url, []];
        }
        $target = parse_url($override);
        $parts  = parse_url($url);
        if (!is_array($target) || empty($target['host']) || !is_array($parts) || empty($parts['host'])) {
            return [$url, []];
        }
        $transport = ($target['scheme'] ?? 'http') . '://' . $target['host']
            . (isset($target['port']) ? ':' . $target['port'] : '')
            . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $headers = ['Host: ' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')];
        if (strtolower((string) ($parts['scheme'] ?? 'http')) === 'https'
            && strtolower((string) ($target['scheme'] ?? 'http')) !== 'https'
        ) {
            $headers[] = 'X-Forwarded-Proto: https';
        }

        return [$transport, $headers];
    }

    private function options(
        string $url,
        array $headers,
        string $method,
        bool $follow,
        int $maxBytes,
        array &$captured,
        array $resolve = []
    ): array {
        $own    = UrlHelper::sameHost($url, $this->ownHost);
        $verify = $own ? $this->verifyTls : true;

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$captured): int {
                $trimmed = trim($line);
                if (preg_match('~^HTTP/\S+\s+\d+~i', $trimmed) === 1) {
                    $captured = [];
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $captured[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ];
        if ($method === 'GET' && $maxBytes > 0) {
            $options[CURLOPT_RANGE] = '0-' . ($maxBytes - 1);
        }
        if ($resolve !== []) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }
        if (!$own) {
            $options[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            if (defined('CURLOPT_PREREQFUNCTION')) {
                $options[CURLOPT_PREREQFUNCTION] = static function ($handle, string $primaryIp): int {
                    return self::isPublicIp($primaryIp) ? CURL_PREREQFUNC_OK : CURL_PREREQFUNC_ABORT;
                };
            }
        }

        return $options;
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            trim($ip, '[]'),
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function pinHost(string $url, ?string &$error): array
    {
        $error = '';
        if (UrlHelper::sameHost($url, $this->ownHost)) {
            return [];
        }
        $parts = parse_url($url);
        $host  = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if ($host === '') {
            return [];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port   = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $key    = $host . ':' . $port;
        if (!isset($this->pins[$key])) {
            $this->pins[$key] = $this->resolvePin($host, $port);
        }
        [$resolve, $error] = $this->pins[$key];

        return $resolve;
    }

    private function resolvePin(string $host, int $port): array
    {
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ips     = $literal ? [$host] : gethostbynamel($host);
        if ($ips === false || $ips === []) {
            return [[], 'Could not resolve host: ' . $host];
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return [[], 'Blocked: ' . $host . ' resolves to a private or reserved address'];
            }
        }

        return [$literal ? [] : [$host . ':' . $port . ':' . $ips[0]], ''];
    }

    private function toResult(string $url, $ch, string $body, array $headers): ProbeResult
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = $status === 0 ? (string) curl_error($ch) : '';
        if ($status === 206) {
            $status = 200;
        }

        return new ProbeResult(
            url: $url,
            status: $status,
            error: $error,
            unverifiable: in_array($status, self::UNVERIFIABLE_STATUSES, true)
                && self::isUnverifiableHost(UrlHelper::host($url)),
            headers: $headers,
            body: $body,
            ttfbMs: round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000, 1),
            totalMs: round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 1),
            effectiveUrl: (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)
        );
    }
}
