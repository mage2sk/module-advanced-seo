<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Blog;

use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects whether a supported blog module is installed and provides a minimal
 * bridge to retrieve blog post URLs for sitemap generation.
 *
 * Supported blog post model class names are injected via DI (see di.xml) so
 * that no vendor-specific references are hard-coded in this class.
 *
 * ObjectManager is used intentionally: the blog module is an optional soft
 * dependency and may not be installed. DI constructor injection would cause a
 * fatal error if the module is absent.
 */
class BlogDetector
{
    /** @var string[] Supported blog post model classes, injected via DI */
    private array $supportedClasses;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param StoreManagerInterface  $storeManager
     * @param LoggerInterface        $logger
     * @param string[]               $supportedClasses Blog post model FQCNs, ordered by preference
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        array $supportedClasses = []
    ) {
        $this->supportedClasses = $supportedClasses;
    }

    /**
     * Check whether any supported blog module is installed.
     */
    public function isBlogInstalled(): bool
    {
        foreach ($this->supportedClasses as $class) {
            if (class_exists($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve blog post URLs and titles for the given store.
     *
     * @return array<int,array{url:string,title:string}>
     */
    public function getBlogPosts(int $storeId): array
    {
        $posts = [];

        try {
            $store   = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

            foreach ($this->supportedClasses as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $posts = $this->loadPostsFromModule($class, $storeId, $baseUrl);
                break; // use the first installed module only
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Panth SEO BlogDetector failed to load blog posts',
                ['error' => $e->getMessage()]
            );
        }

        return $posts;
    }

    /**
     * @return array<int,array{url:string,title:string}>
     */
    private function loadPostsFromModule(string $modelClass, int $storeId, string $baseUrl): array
    {
        $posts = [];

        try {
            /** @var object $collection */
            $collectionFactory = $this->resolveCollectionFactory($modelClass);
            if ($collectionFactory === null) {
                return [];
            }

            $collection = $collectionFactory->create();

            // All three supported modules use addStoreFilter / addFieldToFilter('is_active', 1)
            if (method_exists($collection, 'addStoreFilter')) {
                $collection->addStoreFilter($storeId);
            }
            if (method_exists($collection, 'addFieldToFilter')) {
                $collection->addFieldToFilter('is_active', 1);
            }
            if (method_exists($collection, 'addActiveFilter')) {
                $collection->addActiveFilter();
            }

            foreach ($collection as $post) {
                $url   = $this->resolvePostUrl($post, $baseUrl);
                $title = $this->resolvePostTitle($post);

                if ($url !== '' && $title !== '') {
                    $posts[] = [
                        'url'   => $url,
                        'title' => $title,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Panth SEO BlogDetector: collection load failed',
                ['class' => $modelClass, 'error' => $e->getMessage()]
            );
        }

        return $posts;
    }

    /**
     * Resolve the collection factory for the given post model class.
     *
     * Convention: <Model>\ResourceModel\<Model>\CollectionFactory
     */
    private function resolveCollectionFactory(string $modelClass): ?object
    {
        // Derive collection factory class name following Magento convention
        $parts     = explode('\\', $modelClass);
        $modelName = array_pop($parts);
        $namespace  = implode('\\', $parts);

        $collectionFactoryClass = $namespace . '\\ResourceModel\\' . $modelName . '\\CollectionFactory';

        if (!class_exists($collectionFactoryClass)) {
            return null;
        }

        return $this->objectManager->get($collectionFactoryClass);
    }

    private function resolvePostUrl(object $post, string $baseUrl): string
    {
        // Prefer getPostUrl(), fall back to getUrl()
        if (method_exists($post, 'getPostUrl')) {
            $url = (string) $post->getPostUrl();
            if ($url !== '') {
                return $url;
            }
        }

        if (method_exists($post, 'getUrl')) {
            $url = (string) $post->getUrl();
            if ($url !== '') {
                return $url;
            }
        }

        // Fallback: build from identifier / url_key
        $identifier = '';
        if (method_exists($post, 'getIdentifier')) {
            $identifier = (string) $post->getIdentifier();
        } elseif (method_exists($post, 'getUrlKey')) {
            $identifier = (string) $post->getUrlKey();
        }

        if ($identifier !== '') {
            return $baseUrl . 'blog/' . ltrim($identifier, '/');
        }

        return '';
    }

    private function resolvePostTitle(object $post): string
    {
        if (method_exists($post, 'getTitle')) {
            return (string) $post->getTitle();
        }

        if (method_exists($post, 'getName')) {
            return (string) $post->getName();
        }

        return '';
    }
}
