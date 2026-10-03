<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\StructuredData;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;

class StripInvalidSchemaPropsPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly SchemaAllowList $allowList
    ) {
    }

    public function afterGetJsonLd($subject, $result)
    {
        if (!is_array($result) || $result === []) {
            return $result;
        }
        try {
            if (!$this->config->isAuditFixEnabled(Config::AUDIT_FIX_STRIP_INVALID_SCHEMA_PROPS)) {
                return $result;
            }

            return $this->allowList->strip($result);
        } catch (\Throwable) {
            return $result;
        }
    }
}
