<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap;

/**
 * Writes sitemap_index.xml referencing all shard files.
 */
class IndexWriter
{
    /**
     * @param array<int,array{loc:string,lastmod?:string}> $shards
     * @param string|null $xslHref  Relative XSL filename to reference, or null to skip
     */
    public function write(string $path, array $shards, ?string $xslHref = null): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create sitemap directory: ' . $dir);
        }
        $writer = new \XMLWriter();
        if (!$writer->openUri($path)) {
            throw new \RuntimeException('Cannot open sitemap index: ' . $path);
        }
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        if ($xslHref !== null) {
            $writer->writePi(
                'xml-stylesheet',
                'type="text/xsl" href="' . htmlspecialchars($xslHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"'
            );
        }
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($shards as $shard) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', (string) $shard['loc']);
            if (!empty($shard['lastmod'])) {
                $writer->writeElement('lastmod', (string) $shard['lastmod']);
            }
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
        return $path;
    }
}
