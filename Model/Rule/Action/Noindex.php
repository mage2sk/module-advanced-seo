<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Action;

/**
 * Applies a noindex/nofollow directive to the outgoing meta context.
 */
class Noindex
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $output
     * @return array<string,mixed>
     */
    public function apply(array $params, array $output): array
    {
        $noindex = (bool)($params['noindex'] ?? true);
        $nofollow = (bool)($params['nofollow'] ?? false);

        $robots = [];
        $robots[] = $noindex ? 'noindex' : 'index';
        $robots[] = $nofollow ? 'nofollow' : 'follow';

        $output['noindex'] = $noindex;
        $output['nofollow'] = $nofollow;
        $output['robots'] = implode(',', $robots);
        return $output;
    }
}
