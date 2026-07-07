<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class Ga4MeasurementId extends Value
{
    private const VALIDATION_REGEX = '/^[A-Za-z0-9_\-]{1,64}$/';

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
