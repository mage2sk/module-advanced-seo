<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin;

use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;

trait PageConfigRecorder
{
    private array $applied = [];

    private function recordingPageConfig(): PageConfig
    {
        $this->applied = [];
        $title = $this->createStub(Title::class);
        $title->method('set')->willReturnCallback(function ($value): void {
            $this->applied['title'] = $value;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        foreach (['setDescription' => 'description', 'setKeywords' => 'keywords', 'setRobots' => 'robots'] as $method => $key) {
            $config->method($method)->willReturnCallback(function ($value) use ($key): void {
                $this->applied[$key] = $value;
            });
        }

        return $config;
    }
}
