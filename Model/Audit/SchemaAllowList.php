<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class SchemaAllowList
{
    private const MAX_DEPTH = 12;

    private ?array $types = null;

    private readonly string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 2) . '/etc/schema_properties.json';
    }

    public function getTypes(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $this->types = [];
        $raw = is_readable($this->file) ? (string) file_get_contents($this->file) : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded) && is_array($decoded['types'] ?? null)) {
            foreach ($decoded['types'] as $type => $definition) {
                $this->types[(string) $type] = array_fill_keys((array) ($definition['properties'] ?? []), true);
            }
        }

        return $this->types;
    }

    public static function typeNames(mixed $type): array
    {
        $names = [];
        foreach ((array) $type as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $parts   = preg_split('~[/:#]~', $value) ?: [$value];
            $names[] = (string) end($parts);
        }

        return $names;
    }

    public function isKnownType(string $type): bool
    {
        return isset($this->getTypes()[$type]);
    }

    public function allowedFor(array $typeNames): ?array
    {
        $types   = $this->getTypes();
        $allowed = null;
        foreach ($typeNames as $name) {
            if (isset($types[$name])) {
                $allowed = ($allowed ?? []) + $types[$name];
            }
        }

        return $allowed;
    }

    public function isPropertyAllowed(array $allowed, string $property): bool
    {
        if ($property === '' || str_starts_with($property, '@')) {
            return true;
        }
        if (str_contains($property, ':') || str_contains($property, '/')) {
            return true;
        }
        if (preg_match('/-(input|output)$/', $property) === 1) {
            return true;
        }

        return isset($allowed[$property]);
    }

    public function findInvalid(array $node, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        $found = [];
        if (!array_is_list($node) && isset($node['@type'])) {
            $names   = self::typeNames($node['@type']);
            $allowed = $this->allowedFor($names);
            if ($allowed !== null) {
                foreach (array_keys($node) as $property) {
                    if (!$this->isPropertyAllowed($allowed, (string) $property)) {
                        $found[] = ['type' => $names[0] ?? '', 'property' => (string) $property];
                    }
                }
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $found = array_merge($found, $this->findInvalid($value, $depth + 1));
            }
        }

        return $found;
    }

    public function strip(array $node, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $node;
        }
        if (!array_is_list($node) && isset($node['@type'])) {
            $allowed = $this->allowedFor(self::typeNames($node['@type']));
            if ($allowed !== null) {
                foreach (array_keys($node) as $property) {
                    if (!$this->isPropertyAllowed($allowed, (string) $property)) {
                        unset($node[$property]);
                    }
                }
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->strip($value, $depth + 1);
            }
        }

        return $node;
    }
}
