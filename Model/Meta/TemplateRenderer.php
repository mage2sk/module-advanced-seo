<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Panth\AdvancedSEO\Model\Text\Truncator;
use Psr\Log\LoggerInterface;

class TemplateRenderer
{
    private const MAX_DEPTH = 5;
    private const TOKEN_PATTERN = '/\{\{\s*([a-zA-Z0-9_\.]+)(?::([a-zA-Z0-9_\-]+))?((?:\s*\|\s*[a-zA-Z0-9_]+(?::[^|}]+)?)*)\s*\}\}/u';

    public function __construct(
        private readonly TokenRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly Truncator $truncator
    ) {
    }

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

    private function cleanOutput(string $output): string
    {
        $output = preg_replace('/^\s*[-|]\s*/', '', $output) ?? $output;
        $output = preg_replace('/\s*[-|]\s*$/', '', $output) ?? $output;

        $output = preg_replace('/,\s*,/', ',', $output) ?? $output;

        $output = preg_replace('/^[\s,]+/', '', $output) ?? $output;
        $output = preg_replace('/[\s,]+$/', '', $output) ?? $output;

        $output = preg_replace('/\s*[-]\s*\|\s*/', ' | ', $output) ?? $output;
        $output = preg_replace('/\s*\|\s*[-]\s*/', ' - ', $output) ?? $output;

        $output = preg_replace('/\s{2,}/', ' ', $output) ?? $output;

        return trim($output);
    }

    private function resolveMatch(array $match, mixed $entity, array $context): string
    {
        $tokenName = strtolower($match[1] ?? '');
        $argument  = ($match[2] ?? '') !== '' ? $match[2] : null;
        $filters   = trim($match[3] ?? '');

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
                return $this->truncator->truncate($value, max(4, (int) ($arg ?? '60')));

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
