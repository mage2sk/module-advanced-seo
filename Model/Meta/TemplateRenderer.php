<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Psr\Log\LoggerInterface;

/**
 * Sandboxed token replacement engine.
 *
 * Syntax:
 *   {{name}}                       — plain token
 *   {{attribute:color}}            — token with argument (after colon)
 *   {{name|truncate:60}}           — filter with numeric arg
 *   {{store|default:'Shop'}}       — filter with string arg
 *   {{description|strip|truncate:155}} — chained filters
 *
 * Supported filters: truncate, title, strip, default, upper, lower.
 *
 * There is NO eval and NO Magento variable filter involved. Tokens resolve
 * through TokenRegistry only; unknown tokens render as empty string.
 *
 * Recursion depth limit: 5 (template output is re-rendered if it still
 * contains tokens, e.g. when a token's value itself was a template).
 */
class TemplateRenderer
{
    private const MAX_DEPTH = 5;
    private const TOKEN_PATTERN = '/\{\{\s*([a-zA-Z0-9_\.]+)(?::([a-zA-Z0-9_\-]+))?((?:\s*\|\s*[a-zA-Z0-9_]+(?::[^|}]+)?)*)\s*\}\}/u';

    public function __construct(
        private readonly TokenRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $context
     */
    public function render(string $template, mixed $entity, array $context = []): string
    {
        if ($template === '' || strpos($template, '{{') === false) {
            return $template;
        }

        $depth = 0;
        $output = $template;
        while ($depth < self::MAX_DEPTH && strpos($output, '{{') !== false) {
            $replaced = preg_replace_callback(
                self::TOKEN_PATTERN,
                fn (array $m) => $this->resolveMatch($m, $entity, $context),
                $output
            );
            if ($replaced === null || $replaced === $output) {
                break;
            }
            $output = $replaced;
            $depth++;
        }

        return $this->cleanOutput($output);
    }

    /**
     * Clean up rendered output: remove empty separators, duplicate spaces,
     * trailing/leading punctuation from empty tokens.
     */
    private function cleanOutput(string $output): string
    {
        // Remove patterns like " - " or " | " where one side is empty (start/end of string)
        $output = preg_replace('/^\s*[-|]\s*/', '', $output) ?? $output;
        $output = preg_replace('/\s*[-|]\s*$/', '', $output) ?? $output;

        // Remove empty comma-separated entries: ", ," or ", , ,"
        $output = preg_replace('/,\s*,/', ',', $output) ?? $output;
        // Remove leading/trailing commas with spaces
        $output = preg_replace('/^[\s,]+/', '', $output) ?? $output;
        $output = preg_replace('/[\s,]+$/', '', $output) ?? $output;

        // Remove double separators: " -  | " → " | " or " |  - " → " - "
        $output = preg_replace('/\s*[-]\s*\|\s*/', ' | ', $output) ?? $output;
        $output = preg_replace('/\s*\|\s*[-]\s*/', ' - ', $output) ?? $output;

        // Collapse multiple spaces
        $output = preg_replace('/\s{2,}/', ' ', $output) ?? $output;

        return trim($output);
    }

    /**
     * @param array<int,string> $match
     * @param array<string,mixed> $context
     */
    private function resolveMatch(array $match, mixed $entity, array $context): string
    {
        $tokenName = strtolower($match[1] ?? '');
        $argument  = ($match[2] ?? '') !== '' ? $match[2] : null;
        $filters   = trim($match[3] ?? '');

        // Support dot notation: {{store.name}} → token "store", argument "name"
        if ($argument === null && str_contains($tokenName, '.')) {
            $parts = explode('.', $tokenName, 2);
            $tokenName = $parts[0];
            $argument  = $parts[1];
        }

        $value = '';
        try {
            $resolver = $this->registry->get($tokenName);
            if ($resolver !== null) {
                $value = $resolver->getValue($entity, $context, $argument);
            } elseif (isset($context[$tokenName]) && is_scalar($context[$tokenName])) {
                $value = (string) $context[$tokenName];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO token resolve failed', [
                'token' => $tokenName,
                'error' => $e->getMessage(),
            ]);
            $value = '';
        }

        if ($filters !== '') {
            $value = $this->applyFilters($value, $filters);
        }

        return $value;
    }

    private function applyFilters(string $value, string $filterString): string
    {
        $parts = preg_split('/\s*\|\s*/', ltrim($filterString, '|')) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $name = $part;
            $arg  = null;
            if (strpos($part, ':') !== false) {
                [$name, $arg] = explode(':', $part, 2);
                $name = trim($name);
                $arg  = trim($arg);
                if ($arg !== '' && ($arg[0] === "'" || $arg[0] === '"')) {
                    $arg = trim($arg, "'\"");
                }
            }
            $value = $this->applyFilter(strtolower($name), $value, $arg);
        }
        return $value;
    }

    private function applyFilter(string $name, string $value, ?string $arg): string
    {
        switch ($name) {
            case 'truncate':
                $len = max(1, (int) ($arg ?? '60'));
                if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $len) {
                    return rtrim(mb_substr($value, 0, $len - 1, 'UTF-8')) . '…';
                }
                if (strlen($value) > $len) {
                    return rtrim(substr($value, 0, $len - 1)) . '…';
                }
                return $value;

            case 'title':
                return function_exists('mb_convert_case')
                    ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
                    : ucwords(strtolower($value));

            case 'strip':
                $clean = strip_tags($value);
                return trim((string) preg_replace('/\s+/u', ' ', $clean));

            case 'default':
                return $value === '' ? (string) $arg : $value;

            case 'upper':
                return function_exists('mb_strtoupper')
                    ? mb_strtoupper($value, 'UTF-8')
                    : strtoupper($value);

            case 'lower':
                return function_exists('mb_strtolower')
                    ? mb_strtolower($value, 'UTF-8')
                    : strtolower($value);

            default:
                return $value;
        }
    }
}
