<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Store\Api\Data\StoreInterface;

class XmlFeedWriter
{
    private const GOOGLE_NS = 'http://base.google.com/ns/1.0';

    private ?\XMLWriter $xml = null;
    private string $filePath = '';

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

            if (str_starts_with($fieldName, 'g:')) {
                $localName = substr($fieldName, 2);
                $this->xml->startElementNs('g', $localName, null);
                $this->xml->text($value);
                $this->xml->endElement();
            } else {
                $this->xml->writeElement($fieldName, $value);
            }
        }

        $this->xml->endElement();
    }

    public function flush(): void
    {
        $this->xml?->flush();
    }

    public function close(): void
    {
        if ($this->xml === null) {
            return;
        }

        $this->xml->endElement();
        $this->xml->endElement();
        $this->xml->endDocument();
        $this->xml->flush();
        $this->xml = null;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
