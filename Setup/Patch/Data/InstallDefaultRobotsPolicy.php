<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the `panth_seo_robots_policy` table with sensible defaults:
 * - disallow checkout / customer / cart paths
 * - per-LLM-bot rows (gated by store config) so admins can flip them in UI
 */
class InstallDefaultRobotsPolicy implements DataPatchInterface
{
    private const DEFAULT_DISALLOW_PATHS = [
        '/checkout/',
        '/customer/',
        '/cart/',
        '/*?*SID=',
        '/*?p=',
        '/*?price=',
        '/catalogsearch/',
        '/review/',
        '/sendfriend/',
        '/wishlist/',
    ];

    private const LLM_BOTS = [
        'GPTBot',
        'ClaudeBot',
        'Google-Extended',
        'CCBot',
        'PerplexityBot',
        'Bytespider',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('panth_seo_robots_policy');

        $existing = (int) $connection->fetchOne(
            $connection->select()->from($table, 'COUNT(*)')->where('store_id = ?', 0)
        );
        if ($existing === 0) {
            $rows = [];
            foreach (self::DEFAULT_DISALLOW_PATHS as $i => $path) {
                $rows[] = [
                    'store_id'   => 0,
                    'user_agent' => '*',
                    'directive'  => 'disallow',
                    'path'       => $path,
                    'priority'   => 10 + $i,
                    'is_active'  => 1,
                ];
            }
            foreach (self::LLM_BOTS as $i => $bot) {
                $rows[] = [
                    'store_id'   => 0,
                    'user_agent' => $bot,
                    'directive'  => 'allow',
                    'path'       => '/',
                    'priority'   => 100 + $i,
                    'is_active'  => 1,
                ];
            }
            $connection->insertMultiple($table, $rows);
        }

        $this->moduleDataSetup->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
