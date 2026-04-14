<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor;

use Panth\AdvancedSEO\Controller\Adminhtml\AiSettings\ApproveBatch;

/**
 * Reuses the ApproveBatch flow but delivered under the BulkEditor menu node.
 */
class MassApprove extends ApproveBatch
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';
}
