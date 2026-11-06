<?php

namespace App\Services\Enrichment;

use App\Contracts\JsRendererInterface;
use Spatie\Browsershot\Browsershot;

/**
 * Headless Chromium via spatie/browsershot (Node + Puppeteer on the VPS).
 * Disabled with BROWSERSHOT_ENABLED=false (e.g. on a dev machine without Chromium).
 */
class BrowsershotRenderer implements JsRendererInterface
{
    public function isAvailable(): bool
    {
        return (bool) config('outreach.browsershot.enabled') && class_exists(Browsershot::class);
    }

    public function render(string $url): string
    {
        $shot = Browsershot::url($url)
            ->userAgent((string) config('outreach.crawler.user_agent'))
            ->timeout((int) config('outreach.browsershot.timeout', 30))
            ->waitUntilNetworkIdle()
            ->dismissDialogs()
            ->setOption('args', ['--disable-dev-shm-usage'])
            ->noSandbox();

        if ($node = config('outreach.browsershot.node_path')) {
            $shot->setNodeBinary($node);
        }
        if ($modules = config('outreach.browsershot.node_modules_path')) {
            $shot->setNodeModulePath($modules);
        }
        if ($chrome = config('outreach.browsershot.chrome_path')) {
            $shot->setChromePath($chrome);
        }

        return $shot->bodyHtml();
    }
}
