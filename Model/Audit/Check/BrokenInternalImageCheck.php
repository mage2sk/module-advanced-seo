<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class BrokenInternalImageCheck implements PageCheckInterface
{
    public const CODE          = 'broken_internal_image';
    public const CODE_RELATIVE = 'relative_readme_link';

    private const REPO_HOSTS = ['raw.githubusercontent.com', 'github.com', 'user-images.githubusercontent.com'];

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk()) {
            return [];
        }
        $host   = $ctx->host();
        $entity = null;
        $issues = [];

        foreach ($page->images as $image) {
            $candidates = array_filter(array_merge(
                [(string) ($image['src'] ?? ''), (string) ($image['dataSrc'] ?? '')],
                (array) ($image['srcset'] ?? [])
            ));
            foreach (array_unique($candidates) as $candidate) {
                if (!UrlHelper::sameHost($candidate, $host)) {
                    continue;
                }
                $status = $ctx->statusOf($candidate);
                if ($status !== null && $status >= 400) {
                    $entity ??= $ctx->entityLabel($page->url);
                    $issues[self::CODE . $candidate] = $this->catalog->create(
                        self::CODE,
                        $page->url,
                        $candidate,
                        $this->detail(sprintf('Image returns HTTP %d.', $status), $entity)
                    );
                }
            }

            $rawLink  = (string) ($image['rawLinkHref'] ?? '');
            $linkHref = (string) ($image['linkHref'] ?? '');
            if ($linkHref === '' || !UrlHelper::sameHost($linkHref, $host) || !UrlHelper::isResource($linkHref)) {
                continue;
            }
            $status = $ctx->statusOf($linkHref);
            if ($status !== null && $status >= 400) {
                $entity ??= $ctx->entityLabel($page->url);
                $issues[self::CODE . $linkHref] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    $linkHref,
                    $this->detail(sprintf('Linked image "%s" resolves to this store and returns HTTP %d.', $rawLink, $status), $entity)
                );
            }
            if (UrlHelper::isRelative($rawLink) && $this->isRepositoryImage((string) ($image['src'] ?? ''))) {
                $entity ??= $ctx->entityLabel($page->url);
                $issues[self::CODE_RELATIVE . $rawLink] = $this->catalog->create(
                    self::CODE_RELATIVE,
                    $page->url,
                    $rawLink,
                    $this->detail(sprintf(
                        'Relative README link resolves to %s%s while the image is served from %s.',
                        $linkHref,
                        $status !== null ? ' (HTTP ' . $status . ')' : '',
                        (string) $image['src']
                    ), $entity)
                );
            }
        }

        return array_values($issues);
    }

    private function isRepositoryImage(string $src): bool
    {
        return in_array(UrlHelper::host($src), self::REPO_HOSTS, true);
    }

    private function detail(string $detail, ?string $entity): string
    {
        return $entity !== null && $entity !== '' ? $detail . ' Source: ' . $entity . '.' : $detail;
    }
}
