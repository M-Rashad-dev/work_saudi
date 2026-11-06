<?php

namespace App\Contracts;

/** Renders a JS-heavy page in a headless browser and returns the final HTML. */
interface JsRendererInterface
{
    public function isAvailable(): bool;

    /** @throws \Throwable on render failure */
    public function render(string $url): string;
}
