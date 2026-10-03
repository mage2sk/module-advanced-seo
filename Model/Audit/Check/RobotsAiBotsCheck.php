<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;

class RobotsAiBotsCheck implements SiteCheckInterface
{
    public const CODE            = 'robots_ai_bots';
    public const CODE_PRECEDENCE = 'robots_group_precedence';

    public const AI_BOTS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-SearchBot',
        'PerplexityBot',
        'Google-Extended',
        'Applebot-Extended',
    ];

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function checkSite(array $pages, AuditContext $ctx): array
    {
        $robots = $ctx->robots;
        if ($robots === null) {
            return [];
        }
        $url    = rtrim($ctx->baseUrl, '/') . '/robots.txt';
        $issues = [];

        foreach (self::AI_BOTS as $bot) {
            if (!$robots->isAllowed($bot, '/')) {
                $issues[] = $this->catalog->create(
                    self::CODE,
                    $url,
                    'User-agent: ' . $bot,
                    sprintf('robots.txt blocks %s from the home page, so the store cannot be cited by it.', $bot)
                );
            }
        }
        if (!$robots->hasContentSignal()) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $url,
                'Content-Signal',
                'robots.txt has no Content-Signal line.',
                Issue::SEVERITY_NOTICE
            );
        }

        $star          = $robots->explicitGroupFor('*');
        $starDisallows = [];
        foreach ($star['rules'] ?? [] as [$type, $pattern]) {
            if ($type === 'disallow' && $pattern !== '') {
                $starDisallows[] = $pattern;
            }
        }
        foreach ($robots->getGroups() as $group) {
            if (in_array('*', $group['agents'], true)) {
                continue;
            }
            $disallows = array_filter(
                $group['rules'],
                static fn (array $rule): bool => $rule[0] === 'disallow' && $rule[1] !== ''
            );
            if ($disallows === [] && $starDisallows !== []) {
                $issues[] = $this->catalog->create(
                    self::CODE_PRECEDENCE,
                    $url,
                    'User-agent: ' . implode(', ', $group['agents']),
                    sprintf(
                        'This group only allows, so these bots ignore the * group (%s) and never see its Content-Signal.',
                        implode(', ', array_slice($starDisallows, 0, 6)) . (count($starDisallows) > 6 ? ', ...' : '')
                    )
                );
            }
        }

        $trainYes = false;
        foreach ($robots->getContentSignals() as $signal) {
            if (preg_match('/ai-train\s*=\s*yes/i', (string) $signal) === 1) {
                $trainYes = true;
            }
        }
        if ($trainYes) {
            foreach ($robots->getGroups() as $group) {
                if (in_array('*', $group['agents'], true)) {
                    continue;
                }
                foreach ($group['rules'] as [$type, $pattern]) {
                    if ($type === 'disallow' && $pattern === '/') {
                        $issues[] = $this->catalog->create(
                            self::CODE_PRECEDENCE,
                            $url,
                            'User-agent: ' . implode(', ', $group['agents']),
                            'The bot is disallowed from the whole site while Content-Signal says ai-train=yes.',
                            Issue::SEVERITY_WARNING
                        );
                        break;
                    }
                }
            }
        }

        return $issues;
    }
}
