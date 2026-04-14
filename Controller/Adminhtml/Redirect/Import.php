<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Redirect;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\File\Csv;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Backend\App\Action\Context;

/**
 * Imports redirects from an uploaded CSV file.
 * CSV columns: pattern, target, match_type, status_code, store_id
 */
class Import extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::redirects';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Csv $csv,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $files = $this->getRequest()->getFiles('import_file');

        if (!$files || empty($files['tmp_name'])) {
            $this->messageManager->addErrorMessage(__('No file uploaded.'));
            return $resultRedirect->setPath('*/*/');
        }

        // Validate file extension — only CSV is allowed
        $origName = (string)($files['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $this->messageManager->addErrorMessage(__('Only CSV files are allowed.'));
            return $resultRedirect->setPath('*/*/');
        }

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($files['tmp_name']);
        $allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
            $this->messageManager->addErrorMessage(__('Invalid file type. Only CSV files are allowed.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $dest = $var->getAbsolutePath('panth_seo_import_' . bin2hex(random_bytes(8)) . '.csv');
            if (!move_uploaded_file($files['tmp_name'], $dest)) {
                throw new \RuntimeException('Could not move uploaded file');
            }

            $rows = $this->csv->getData($dest);
            if (file_exists($dest)) {
                try {
                    unlink($dest);
                } catch (\Throwable) {
                    // Best-effort cleanup
                }
            }

            if (!$rows) {
                throw new \RuntimeException('Empty CSV');
            }

            $header = array_map('strtolower', array_map('trim', array_shift($rows)));
            $idx = array_flip($header);
            $required = ['pattern', 'target'];
            foreach ($required as $r) {
                if (!isset($idx[$r])) {
                    throw new \RuntimeException('Missing required column: ' . $r);
                }
            }

            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_redirect');
            $inserted = 0;
            $skipped = 0;
            $allowedMatchTypes = ['literal', 'regex', 'maintenance'];
            $allowedStatusCodes = [301, 302, 307, 308, 410, 451, 503];
            foreach ($rows as $row) {
                $pattern = $this->sanitizeCsvValue(trim((string)($row[$idx['pattern']] ?? '')));
                $target = $this->sanitizeCsvValue(trim((string)($row[$idx['target']] ?? '')));
                if ($pattern === '' || $target === '') {
                    continue;
                }
                // Block dangerous URI schemes in target
                if (preg_match('#^(javascript|data|vbscript):#i', $target)) {
                    $skipped++;
                    continue;
                }
                $matchType = (string)($row[$idx['match_type'] ?? -1] ?? 'literal');
                if (!in_array($matchType, $allowedMatchTypes, true)) {
                    $matchType = 'literal';
                }
                $statusCode = (int)($row[$idx['status_code'] ?? -1] ?? 301);
                if (!in_array($statusCode, $allowedStatusCodes, true)) {
                    $statusCode = 301;
                }
                $connection->insertOnDuplicate($table, [
                    'pattern' => mb_substr($pattern, 0, 2048),
                    'target' => mb_substr($target, 0, 2048),
                    'match_type' => $matchType,
                    'status_code' => $statusCode,
                    'store_id' => (int)($row[$idx['store_id'] ?? -1] ?? 0),
                    'is_active' => 1,
                    'priority' => 10,
                    'created_at' => $this->dateTime->gmtDate(),
                    'updated_at' => $this->dateTime->gmtDate(),
                ], ['target', 'match_type', 'status_code', 'updated_at']);
                $inserted++;
            }
            if ($skipped > 0) {
                $this->messageManager->addWarningMessage(__('%1 row(s) skipped due to invalid data.', $skipped));
            }

            $this->messageManager->addSuccessMessage(__('%1 redirect(s) imported.', $inserted));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Strip leading formula-injection characters from CSV cell values.
     * Prevents spreadsheet formula execution (=, +, -, @, \t, \r).
     */
    private function sanitizeCsvValue(string $value): string
    {
        return ltrim($value, "=+\-@\t\r");
    }
}
