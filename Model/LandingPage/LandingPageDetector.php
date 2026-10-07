<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\LandingPage;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class LandingPageDetector
{
    private const IDENTIFIER_PREFIX = 'landing-';
    private const LAYOUT_HANDLE    = 'landing_page';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isLandingPage(PageInterface $page): bool
    {
        $identifier = (string) $page->getIdentifier();
        if ($identifier !== '' && str_starts_with($identifier, self::IDENTIFIER_PREFIX)) {
            return true;
        }

        $layoutUpdate = (string) $page->getData('layout_update_xml');
        if ($layoutUpdate !== '' && str_contains($layoutUpdate, self::LAYOUT_HANDLE)) {
            return true;
        }

        $customLayoutUpdate = (string) $page->getData('custom_layout_update_xml');
        if ($customLayoutUpdate !== '' && str_contains($customLayoutUpdate, self::LAYOUT_HANDLE)) {
            return true;
        }

        return false;
    }

    public function getLandingPages(int $storeId): array
    {
        try {
            $connection = $this->resource->getConnection();
            $pageTable  = $this->resource->getTableName('cms_page');
            $storeTable = $this->resource->getTableName('cms_page_store');

            $identifierCond   = $connection->quoteInto('p.identifier LIKE ?', self::IDENTIFIER_PREFIX . '%');
            $layoutCond       = $connection->quoteInto('p.layout_update_xml LIKE ?', '%' . self::LAYOUT_HANDLE . '%');
            $customLayoutCond = $connection->quoteInto(
                'p.custom_layout_update_xml LIKE ?',
                '%' . self::LAYOUT_HANDLE . '%'
            );

            $select = $connection->select()
                ->from(['p' => $pageTable])
                ->join(['ps' => $storeTable], 'ps.page_id = p.page_id', [])
                ->where('p.is_active = ?', 1)
                ->where('ps.store_id IN (?)', [0, $storeId])
                ->where("({$identifierCond} OR {$layoutCond} OR {$customLayoutCond})")
                ->group('p.page_id');

            return $connection->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO: failed to fetch landing pages', [
                'store_id' => $storeId,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }
    }
}
