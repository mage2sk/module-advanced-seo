<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\UrlHelper;

class PermanentRedirectLinkCheck implements PageCheckInterface
{
    public const CODE                = 'permanent_redirect_link';
    public const CODE_TRAILING_SLASH = 'trailing_slash_internal_link';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk()) {
            return [];
        }
        $issues = [];
        foreach ($page->links as $link) {
            if (empty($link['isInternal'])) {
                continue;
            }
            $href   = (string) $link['href'];
            $status = $ctx->statusOf($href);
            if ($status !== 301 && $status !== 308) {
                continue;
            }
            $final   = $ctx->finalUrlOf($href);
            $path    = UrlHelper::path($href);
            $slashed = $path !== '/' && str_ends_with($path, '/')
                && $final !== '' && UrlHelper::normalize($final) === UrlHelper::trailingSlashTwin($href);
            $slug    = trim($path, '/');

            $issues[self::CODE . $href] = $this->catalog->create(
                self::CODE,
                $page->url,
                $href,
                sprintf(
                    'Linked from %s; %s redirects %d to %s.%s',
                    $page->url,
                    $href,
                    $status,
                    $final !== '' ? $final : '(unknown target)',
                    $slashed
                        ? sprintf(' Build the link with $block->getUrl(\'\', [\'_direct\' => \'%s\']).', $slug)
                        : ''
                )
            );
            if ($slashed) {
                $issues[self::CODE_TRAILING_SLASH . $href] = $this->catalog->create(
                    self::CODE_TRAILING_SLASH,
                    $page->url,
                    $href,
                    sprintf(
                        'Trailing slash 301s to %s. Likely $block->getUrl(\'%s\'); use $block->getUrl(\'\', [\'_direct\' => \'%s\']).',
                        $final,
                        $slug,
                        $slug
                    )
                );
            }
        }

        return array_values($issues);
    }
}
