<?php

/*
|--------------------------------------------------------------------------
| Saudi Dev Companies Daily — pipeline configuration
|--------------------------------------------------------------------------
| Infrastructure-level values live here (from .env). User-editable values
| (message template, batch size, batch time, min relevance…) live in the
| `settings` table and are read through App\Services\Settings\SettingsRepository.
*/

return [

    'timezone' => env('APP_TIMEZONE', 'Africa/Cairo'),

    // Daily discovery run time (batch time is a DB setting, editable in the UI).
    'discover_time' => env('OUTREACH_DISCOVER_TIME', '03:00'),

    // Stop analyzing once this many qualified, unshown companies are in the pool.
    'pool_target' => (int) env('OUTREACH_POOL_TARGET', 30),

    'cities' => [
        // key => Arabic display name, English name, extra spellings used to detect the city in text.
        'riyadh' => ['ar' => 'الرياض', 'en' => 'Riyadh', 'aliases' => ['Riyad', 'Ar Riyadh']],
        'jeddah' => ['ar' => 'جدة', 'en' => 'Jeddah', 'aliases' => ['Jedda', 'Jiddah', 'Jidda']],
        'dammam' => ['ar' => 'الدمام', 'en' => 'Dammam', 'aliases' => ['Ad Dammam', 'Eastern Province', 'المنطقة الشرقية']],
        'khobar' => ['ar' => 'الخبر', 'en' => 'Khobar', 'aliases' => ['Al Khobar', 'Al-Khobar', 'Alkhobar']],
        'makkah' => ['ar' => 'مكة', 'en' => 'Makkah', 'aliases' => ['Mecca', 'Makka', 'مكة المكرمة']],
        'madinah' => ['ar' => 'المدينة المنورة', 'en' => 'Madinah', 'aliases' => ['Medina', 'Al Madinah', 'Madina']],
        'abha' => ['ar' => 'أبها', 'en' => 'Abha', 'aliases' => ['Khamis Mushait', 'خميس مشيط']],
        'tabuk' => ['ar' => 'تبوك', 'en' => 'Tabuk', 'aliases' => ['Tabouk']],
        'qassim' => ['ar' => 'القصيم', 'en' => 'Qassim', 'aliases' => ['Buraidah', 'Buraydah', 'Unaizah', 'بريدة', 'عنيزة', 'Al Qassim']],
    ],

    'searxng' => [
        'url' => rtrim((string) env('SEARXNG_URL', 'http://127.0.0.1:8888'), '/'),
        'max_queries_per_run' => (int) env('SEARXNG_MAX_QUERIES', 25),
        'delay_min_seconds' => (int) env('SEARXNG_DELAY_MIN', 5),
        'delay_max_seconds' => (int) env('SEARXNG_DELAY_MAX', 15),
        'max_consecutive_failures' => (int) env('SEARXNG_MAX_FAILURES', 3),
        'timeout' => 20,
        'cache_ttl_hours' => 24,
    ],

    'overpass' => [
        'url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'timeout' => 90,
    ],

    'serper' => [
        'api_key' => env('SERPER_API_KEY'),
        'url' => 'https://google.serper.dev/search',
        // Free credits are finite: a few searches per night (rotation continues after SearXNG's).
        'max_queries_per_run' => (int) env('SERPER_MAX_QUERIES', 5),
    ],

    'crawler' => [
        'user_agent' => env('CRAWLER_USER_AGENT', 'SaudiDevDailyBot/1.0 (+personal job-search tool; respects robots.txt)'),
        'max_pages_per_site' => 6,
        'timeout' => 10,
        'delay_min_ms' => 1000,
        'delay_max_ms' => 3000,
        'min_visible_text' => 300,
        // Some sites send an incomplete TLS chain and fail verification (cURL error 60).
        // true = retry those once WITHOUT certificate verification (read-only public pages, no data sent).
        // Security trade-off: off by default.
        'allow_insecure_tls' => (bool) env('CRAWLER_ALLOW_INSECURE_TLS', false),
    ],

    'browsershot' => [
        'enabled' => (bool) env('BROWSERSHOT_ENABLED', true),
        'max_renders_per_site' => 2,
        'node_path' => env('BROWSERSHOT_NODE_PATH'),
        // Where the `puppeteer` package lives when installed globally (npm -g), e.g. /usr/lib/node_modules
        'node_modules_path' => env('BROWSERSHOT_NODE_MODULES_PATH'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        'timeout' => 30,
    ],

    'llm' => [
        'drivers' => array_values(array_filter(array_map('trim', explode(',', (string) env('LLM_DRIVERS', 'gemini,ollama,heuristic'))))),
        'rate_per_minute' => (int) env('LLM_RATE_PER_MINUTE', 10),
        'max_input_chars' => 4000,
        // Exponential backoff (ms) on Gemini 429/5xx before falling back to the next driver.
        'retry_backoff_ms' => [5000, 20000],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL') ?: 'gemini-flash-latest',
            'url' => 'https://generativelanguage.googleapis.com/v1beta',
        ],

        'ollama' => [
            'url' => env('OLLAMA_URL'),
            'model' => env('OLLAMA_MODEL'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        ],
    ],

];
