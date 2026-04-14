<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backend model for `panth_seo/analytics/ga4_measurement_id`.
 *
 * Trims whitespace and rejects any value that is not a plain alphanumeric
 * identifier with optional dashes/underscores (max 64 chars). This prevents
 * a compromised admin session from injecting HTML or JavaScript into the
 * frontend gtag snippet via the measurement ID field.
 */
class Ga4MeasurementId extends Value
{
    private const VALIDATION_REGEX = '/^[A-Za-z0-9_\-]{1,64}$/';

    /**
     * @throws LocalizedException when the submitted value is not a well-formed
     *         GA4 measurement ID.
     */
    public function beforeSave(): self
    {
        $value = trim((string) $this->getValue());
        $this->setValue($value);

        if ($value === '') {
            return parent::beforeSave();
        }

        if (!preg_match(self::VALIDATION_REGEX, $value)) {
            throw new LocalizedException(
                __(
                    'GA4 Measurement ID must match the pattern G-XXXXXXXXXX '
                    . '(letters, digits, dash, underscore; max 64 characters).'
                )
            );
        }

        return parent::beforeSave();
    }
}
