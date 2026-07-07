<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Framework\App\RequestInterface;

class SearchQueryToken implements TokenInterface
{
    private const MAX_LENGTH = 100;

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        $query = (string) ($context['search_query'] ?? $this->request->getParam('q', ''));

        $query = strip_tags($query);
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));

        if ($query === '') {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($query, 'UTF-8') > self::MAX_LENGTH) {
            return mb_substr($query, 0, self::MAX_LENGTH, 'UTF-8');
        }

        if (strlen($query) > self::MAX_LENGTH) {
            return substr($query, 0, self::MAX_LENGTH);
        }

        return $query;
    }
}
