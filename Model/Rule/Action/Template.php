<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Action;

/**
 * Applies title/description/og templates with variable substitution.
 * Variables: {{name}}, {{sku}}, {{price}}, {{brand}}, {{category}}, {{store_name}} etc.
 */
class Template
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $context
     * @param array<string,mixed> $output
     * @return array<string,mixed>
     */
    public function apply(array $params, array $context, array $output): array
    {
        $map = [
            'title_template' => 'title',
            'description_template' => 'description',
            'og_template' => 'og_title',
        ];

        foreach ($map as $paramKey => $outKey) {
            $tpl = (string)($params[$paramKey] ?? '');
            if ($tpl === '') {
                continue;
            }
            $output[$outKey] = $this->render($tpl, $context);
        }

        return $output;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function render(string $template, array $context): string
    {
        $vars = $this->flatten($context);
        return (string)preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                return isset($vars[$key]) ? (string)$vars[$key] : '';
            },
            $template
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,scalar>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_object($v) && method_exists($v, 'getData')) {
                $v = $v->getData();
            }
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$key] = $v === null ? '' : $v;
            }
        }
        return $out;
    }
}
