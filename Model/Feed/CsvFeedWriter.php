<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Writes feed output in CSV format with a header row derived from field names.
 */
class CsvFeedWriter
{
    /** @var resource|null */
    private $fileHandle = null;
    private string $filePath = '';
    private bool $headerWritten = false;

    /**
     * Open a new CSV file for writing.
     */
    public function open(string $filePath, StoreInterface $store): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->filePath = $filePath;
        $this->fileHandle = fopen($filePath, 'w');
        $this->headerWritten = false;

        if ($this->fileHandle === false) {
            throw new \RuntimeException('Failed to open file for writing: ' . $filePath);
        }

        // Write UTF-8 BOM for Excel compatibility
        fwrite($this->fileHandle, "\xEF\xBB\xBF");
    }

    /**
     * Write a single product row with resolved field values.
     *
     * On the first call, the header row is written from the field names.
     *
     * @param array<string, string> $fields Associative array of field_name => resolved_value
     */
    public function writeItem(array $fields): void
    {
        if ($this->fileHandle === null) {
            return;
        }

        // Write header row on first item
        if (!$this->headerWritten) {
            fputcsv($this->fileHandle, array_keys($fields));
            $this->headerWritten = true;
        }

        fputcsv($this->fileHandle, array_values($fields));
    }

    /**
     * Flush output buffer to disk.
     */
    public function flush(): void
    {
        if ($this->fileHandle !== null) {
            fflush($this->fileHandle);
        }
    }

    /**
     * Close the CSV file handle.
     */
    public function close(): void
    {
        if ($this->fileHandle !== null) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Get the file path that was written.
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
