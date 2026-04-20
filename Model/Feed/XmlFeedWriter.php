<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Writes Google Shopping XML feed (RSS 2.0 with g: namespace).
 *
 * Uses streaming XMLWriter to keep memory usage constant regardless of product count.
 */
class XmlFeedWriter
{
    private const GOOGLE_NS = 'http://base.google.com/ns/1.0';

    private ?\XMLWriter $xml = null;
    private string $filePath = '';

    /**
     * Open a new feed file for writing.
     */
    public function open(string $filePath, StoreInterface $store): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->filePath = $filePath;
        $this->xml = new \XMLWriter();
        $this->xml->openUri($filePath);
        $this->xml->setIndent(true);
        $this->xml->setIndentString('  ');
        $this->xml->startDocument('1.0', 'UTF-8');

        $this->xml->startElement('rss');
        $this->xml->writeAttribute('version', '2.0');
        $this->xml->writeAttribute('xmlns:g', self::GOOGLE_NS);

        $this->xml->startElement('channel');
        $this->xml->writeElement('title', (string) $store->getName());
        $this->xml->writeElement('link', $store->getBaseUrl());
        $this->xml->writeElement('description', 'Product feed for ' . $store->getName());
    }

    /**
     * Write a single product item with resolved field values.
     *
     * @param array<string, string> $fields Associative array of field_name => resolved_value
     */
    public function writeItem(array $fields): void
    {
        if ($this->xml === null) {
            return;
        }

        $this->xml->startElement('item');

        foreach ($fields as $fieldName => $value) {
            if ($value === '') {
                continue;
            }

            // g:shipping is the one Google field that requires nested children
            // (<g:country>, <g:price>) instead of flat text. The resolver
            // packs both pieces into one string with ":::" as a delimiter
            // (e.g. "IN:::0.00 INR") because the field-mapping table can only
            // store a single scalar per row.
            if ($fieldName === 'g:shipping') {
                $parts = explode(':::', $value, 2);
                if (count($parts) === 2) {
                    $this->xml->startElementNs('g', 'shipping', null);
                    $this->xml->startElementNs('g', 'country', null);
                    $this->xml->text(trim($parts[0]));
                    $this->xml->endElement();
                    $this->xml->startElementNs('g', 'price', null);
                    $this->xml->text(trim($parts[1]));
                    $this->xml->endElement();
                    $this->xml->endElement();
                    continue;
                }
            }

            // Determine if this is a g: namespaced field
            if (str_starts_with($fieldName, 'g:')) {
                $localName = substr($fieldName, 2);
                $this->xml->startElementNs('g', $localName, null);
                $this->xml->text($value);
                $this->xml->endElement();
            } else {
                $this->xml->writeElement($fieldName, $value);
            }
        }

        $this->xml->endElement(); // item
    }

    /**
     * Flush the current buffer to disk (call periodically for large feeds).
     */
    public function flush(): void
    {
        $this->xml?->flush();
    }

    /**
     * Close the feed file and finalize the XML document.
     */
    public function close(): void
    {
        if ($this->xml === null) {
            return;
        }

        $this->xml->endElement(); // channel
        $this->xml->endElement(); // rss
        $this->xml->endDocument();
        $this->xml->flush();
        $this->xml = null;
    }

    /**
     * Get the file path that was written.
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
