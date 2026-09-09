<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Api\RuleEvaluatorInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Panth\AdvancedSEO\Model\Text\Truncator;
use Psr\Log\LoggerInterface;

class Resolver implements MetaResolverInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ResolvedRepository $resolvedRepository,
        private readonly ResolvedMetaFactory $resolvedFactory,
        private readonly Cache $cache,
        private readonly TemplateCollectionFactory $templateCollectionFactory,
        private readonly TemplateRenderer $renderer,
        private readonly RuleEvaluatorInterface $ruleEngine,
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Truncator $truncator,
        private readonly ?SeoDebugLogger $seoDebugLogger = null
    ) {
    }

    private function debug(string $message, array $context = []): void
    {
        if ($this->seoDebugLogger === null) {
            return;
        }
        if (!$this->config->isDebug()) {
            return;
        }
        $this->seoDebugLogger->debug($message, $context);
    }

    public function resolve(string $entityType, int $entityId, int $storeId): ResolvedMetaInterface
    {
        $context = [];

        $cached = $this->cache->load($entityType, $entityId, $storeId);
        if ($cached !== null) {
            $this->debug('panth_seo: meta.resolved', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'source' => 'cache',
            ]);
            return $cached;
        }

        $fast = $this->resolvedRepository->find($entityType, $entityId, $storeId);
        if ($fast !== null && $fast->getMetaTitle() !== null) {
            $fast = $this->applyLiveRules($fast, $entityType, $entityId, $storeId);
            $this->cache->save($fast);
            $this->debug('panth_seo: meta.resolved', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'source' => 'indexer',
                'meta_source' => $fast->getSource(),
            ]);
            return $fast;
        }

        $resolved = $this->renderLive($entityType, $entityId, $storeId, $context);
        $this->cache->save($resolved);
        $this->debug('panth_seo: meta.resolved', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'source' => 'live',
            'meta_source' => $resolved->getSource(),
        ]);
        return $resolved;
    }

    public function resolveBatch(string $entityType, array $entityIds, int $storeId): array
    {
        $out = [];
        if ($entityIds === []) {
            return $out;
        }

        foreach ($entityIds as $id) {
            $id = (int) $id;
            try {
                $out[$id] = $this->renderLive($entityType, $id, $storeId, []);
            } catch (\Throwable $e) {
                $this->logger->warning('Panth SEO resolveBatch failed', [
                    'entity_type' => $entityType,
                    'entity_id'   => $id,
                    'store_id'    => $storeId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
        return $out;
    }

    private function applyLiveRules(
        ResolvedMetaInterface $resolved,
        string $entityType,
        int $entityId,
        int $storeId
    ): ResolvedMetaInterface {
        try {
            $ruleResult = $this->ruleEngine->evaluate($entityType, $entityId, $storeId, [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'store_id'    => $storeId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO rule engine failed', ['error' => $e->getMessage()]);
            return $resolved;
        }

        if (empty($ruleResult['matched_rules'])) {
            return $resolved;
        }

        $needsEntity = !empty($ruleResult['title_template']) || !empty($ruleResult['description_template']);
        $entity      = $needsEntity ? $this->loadEntity($entityType, $entityId, $storeId) : null;
        $context     = [
            'store_id'    => $storeId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ];

        if (!empty($ruleResult['title_template'])) {
            $resolved->setMetaTitle($this->truncate(
                $this->renderer->render((string) $ruleResult['title_template'], $entity, $context),
                $this->config->getTitleMaxLength($storeId)
            ));
        }

        if (!empty($ruleResult['description_template'])) {
            $resolved->setMetaDescription($this->truncate(
                $this->renderer->render((string) $ruleResult['description_template'], $entity, $context),
                $this->config->getDescriptionMaxLength($storeId)
            ));
        }

        if (!empty($ruleResult['noindex'])) {
            $resolved->setRobots(!empty($ruleResult['nofollow']) ? 'noindex,nofollow' : 'noindex,follow');
        }

        if (!empty($ruleResult['canonical']) && is_string($ruleResult['canonical'])) {
            $resolved->setCanonicalUrl($ruleResult['canonical']);
        }

        $resolved->setSource('rule');

        return $resolved;
    }

    private function renderLive(string $entityType, int $entityId, int $storeId, array $context): ResolvedMetaInterface
    {
        $entity = $this->loadEntity($entityType, $entityId, $storeId);

        $context['store_id']    = $storeId;
        $context['entity_type'] = $entityType;
        $context['entity_id']   = $entityId;

        $ruleContext = $context;
        if ($entity instanceof ProductInterface) {
            $ruleContext['product'] = $entity;
            $ruleContext['sku']     = $entity->getSku();
            $ruleContext['name']    = $entity->getName();
            $ruleContext['price']   = (float) $entity->getFinalPrice();
        } elseif ($entity instanceof CategoryInterface) {
            $ruleContext['category'] = $entity;
            $ruleContext['name']     = $entity->getName();
        } elseif ($entity instanceof PageInterface) {
            $ruleContext['page'] = $entity;
            $ruleContext['name'] = $entity->getTitle();
        }

        $ruleResult = [];
        try {
            $ruleResult = $this->ruleEngine->evaluate($entityType, $entityId, $storeId, $ruleContext);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO rule engine failed', ['error' => $e->getMessage()]);
        }

        $override = $this->loadOverride($entityType, $entityId, $storeId);
        $template = $this->loadTemplate($entityType, $storeId);

        $title = $this->pickTitle($override, $ruleResult, $template, $entity, $context);
        $desc  = $this->pickDescription($override, $ruleResult, $template, $entity, $context);
        $kw    = $this->pickKeywords($override, $template, $entity, $context);
        $robots = $this->pickRobots($override, $ruleResult, $template, $storeId);
        $canonical = $this->pickCanonical($override, $ruleResult, $entityType, $entityId, $storeId);
        $source = $this->determineSource($override, $ruleResult, $template);

        $dto = $this->resolvedFactory->create();
        $dto->setStoreId($storeId);
        $dto->setEntityType($entityType);
        $dto->setEntityId($entityId);
        $dto->setMetaTitle($this->truncate($title, $this->config->getTitleMaxLength($storeId)));
        $dto->setMetaDescription($this->truncate($desc, $this->config->getDescriptionMaxLength($storeId)));
        $dto->setMetaKeywords($kw);
        $dto->setCanonicalUrl($canonical);
        $dto->setRobots($robots);
        $dto->setSource($source);
        return $dto;
    }

    private function loadEntity(string $entityType, int $entityId, int $storeId): mixed
    {
        try {
            return match ($entityType) {
                MetaResolverInterface::ENTITY_PRODUCT  => $this->productRepository->getById($entityId, false, $storeId),
                MetaResolverInterface::ENTITY_CATEGORY => $this->categoryRepository->get($entityId, $storeId),
                MetaResolverInterface::ENTITY_CMS      => $this->pageRepository->getById($entityId),
                default => null,
            };
        } catch (NoSuchEntityException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO entity load failed', [
                'type'  => $entityType,
                'id'    => $entityId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function loadOverride(string $entityType, int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('panth_seo_override'))
            ->where('entity_type = ?', $entityType)
            ->where('entity_id = ?', $entityId)
            ->where('store_id IN (?)', [$storeId, 0])
            ->order(new \Zend_Db_Expr('store_id DESC'))
            ->limit(1);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    private function loadTemplate(string $entityType, int $storeId): ?array
    {
        if (!$this->config->useTemplates($storeId)) {
            return null;
        }
        $collection = $this->templateCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('entity_type', $entityType)
            ->addFieldToFilter('store_id', ['in' => [$storeId, 0]])
            ->setOrder('store_id', 'DESC')
            ->setOrder('priority', 'ASC')
            ->setPageSize(1);
        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }
        return $item->getData();
    }

    private function pickTitle(?array $override, array $rule, ?array $template, mixed $entity, array $context): ?string
    {
        if ($override && !empty($override['meta_title']) && $this->overrideUsable($override)) {
            return (string) $override['meta_title'];
        }
        if (!empty($rule['title_template'])) {
            return $this->renderer->render((string) $rule['title_template'], $entity, $context);
        }
        $storeId = (int) ($context['store_id'] ?? 0);
        if ($template && !empty($template['meta_title'])) {
            if (!$this->config->isForceTemplateOverExisting($storeId)) {
                $native = $this->nativeMetaTitle($entity);
                if ($native !== null && $native !== '') {
                    return $native;
                }
            }
            return $this->renderer->render((string) $template['meta_title'], $entity, $context);
        }
        return $this->nativeTitle($entity);
    }

    private function pickDescription(?array $override, array $rule, ?array $template, mixed $entity, array $context): ?string
    {
        if ($override && !empty($override['meta_description']) && $this->overrideUsable($override)) {
            return (string) $override['meta_description'];
        }
        if (!empty($rule['description_template'])) {
            return $this->renderer->render((string) $rule['description_template'], $entity, $context);
        }
        $storeId = (int) ($context['store_id'] ?? 0);
        if ($template && !empty($template['meta_description'])) {
            if (!$this->config->isForceTemplateOverExisting($storeId)) {
                $native = $this->nativeMetaDescription($entity);
                if ($native !== null && $native !== '') {
                    return $native;
                }
            }
            return $this->renderer->render((string) $template['meta_description'], $entity, $context);
        }
        return $this->nativeDescription($entity);
    }

    private function pickKeywords(?array $override, ?array $template, mixed $entity, array $context): ?string
    {
        if ($override && !empty($override['meta_keywords']) && $this->overrideUsable($override)) {
            return (string) $override['meta_keywords'];
        }
        if ($template && !empty($template['meta_keywords'])) {
            return $this->renderer->render((string) $template['meta_keywords'], $entity, $context);
        }
        return $this->nativeKeywords($entity);
    }

    private function pickRobots(?array $override, array $rule, ?array $template, int $storeId): ?string
    {
        if ($override && !empty($override['robots']) && $this->overrideUsable($override)) {
            return (string) $override['robots'];
        }
        if (!empty($rule['noindex'])) {
            return 'noindex,follow';
        }
        if ($template && !empty($template['robots'])) {
            return (string) $template['robots'];
        }
        return '';
    }

    private function pickCanonical(?array $override, array $rule, string $entityType, int $entityId, int $storeId): ?string
    {
        if ($override && !empty($override['canonical_url'])) {
            return (string) $override['canonical_url'];
        }
        if (!empty($rule['canonical']) && is_string($rule['canonical'])) {
            return $rule['canonical'];
        }
        try {
            return $this->canonicalResolver->getCanonicalUrl($entityType, $entityId, $storeId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function determineSource(?array $override, array $rule, ?array $template): string
    {
        if ($override && $this->overrideUsable($override)) {
            return 'override';
        }
        if (!empty($rule['matched_rules'])) {
            return 'rule';
        }
        if ($template) {
            return 'template';
        }
        return 'fallback';
    }

    private function overrideUsable(array $override): bool
    {
        return $override !== [];
    }

    private function nativeMetaTitle(mixed $entity): ?string
    {
        if ($entity instanceof ProductInterface || $entity instanceof CategoryInterface) {
            $v = (string) ($entity->getData('meta_title') ?? '');
            return $v !== '' ? $v : null;
        }
        if ($entity instanceof PageInterface) {
            $v = (string) $entity->getMetaTitle();
            return $v !== '' ? $v : null;
        }
        return null;
    }

    private function nativeMetaDescription(mixed $entity): ?string
    {
        if ($entity instanceof ProductInterface) {
            $meta = $entity->getCustomAttribute('meta_description');
            if ($meta !== null && $meta->getValue() !== null && (string) $meta->getValue() !== '') {
                return (string) $meta->getValue();
            }
            return null;
        }
        if ($entity instanceof CategoryInterface) {
            $v = (string) ($entity->getData('meta_description') ?? '');
            return $v !== '' ? $v : null;
        }
        if ($entity instanceof PageInterface) {
            $v = (string) $entity->getMetaDescription();
            return $v !== '' ? $v : null;
        }
        return null;
    }

    private function nativeTitle(mixed $entity): ?string
    {
        if ($entity instanceof ProductInterface || $entity instanceof CategoryInterface) {
            $meta = (string) ($entity->getData('meta_title') ?? '');
            if ($meta !== '') {
                return $meta;
            }
            return (string) $entity->getName();
        }
        if ($entity instanceof PageInterface) {
            $meta = (string) $entity->getMetaTitle();
            return $meta !== '' ? $meta : (string) $entity->getTitle();
        }
        return null;
    }

    private function nativeDescription(mixed $entity): ?string
    {
        if ($entity instanceof ProductInterface) {
            $meta = $entity->getCustomAttribute('meta_description');
            if ($meta !== null && $meta->getValue() !== null) {
                return (string) $meta->getValue();
            }
            $short = $entity->getCustomAttribute('short_description');
            if ($short !== null && $short->getValue() !== null) {
                return trim(strip_tags((string) $short->getValue()));
            }
            return null;
        }
        if ($entity instanceof CategoryInterface) {
            $meta = (string) ($entity->getData('meta_description') ?? '');
            return $meta !== '' ? $meta : trim(strip_tags((string) $entity->getDescription()));
        }
        if ($entity instanceof PageInterface) {
            return (string) $entity->getMetaDescription() ?: null;
        }
        return null;
    }

    private function nativeKeywords(mixed $entity): ?string
    {
        if ($entity instanceof ProductInterface) {
            $meta = $entity->getCustomAttribute('meta_keyword');
            return $meta !== null && $meta->getValue() !== null ? (string) $meta->getValue() : null;
        }
        if ($entity instanceof CategoryInterface) {
            $v = (string) ($entity->getData('meta_keywords') ?? '');
            return $v !== '' ? $v : null;
        }
        if ($entity instanceof PageInterface) {
            return (string) $entity->getMetaKeywords() ?: null;
        }
        return null;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $this->truncator->truncate($value, $max);
    }
}
