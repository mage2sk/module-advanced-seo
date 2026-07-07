<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Store\Api\Data\StoreInterface;

class CsvFeedWriter
{
    private $fileHandle = null;
    private string $filePath = '';
    private bool $headerWritten = false;

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

        fwrite($this->fileHandle, "\xEF\xBB\xBF");
    }

    public function writeItem(array $fields): void
    {
        if ($this->fileHandle === null) {
            return;
        }

        if (!$this->headerWritten) {
            fputcsv($this->fileHandle, array_keys($fields));
            $this->headerWritten = true;
        }

        fputcsv($this->fileHandle, array_values($fields));
    }

    public function flush(): void
    {
        if ($this->fileHandle !== null) {
            fflush($this->fileHandle);
        }
    }

    public function close(): void
    {
        if ($this->fileHandle !== null) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
