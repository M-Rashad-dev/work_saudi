<?php

namespace App\Services\Discovery;

use App\Enums\QueryGroup;
use App\Models\SearchQuery;
use App\Services\Analysis\AnalysisBacklog;
use Illuminate\Support\Facades\DB;

/**
 * Pool health: when the qualified-unshown pool is below target and almost every query has
 * already been run, generate new query variations for the least-covered cities. Normal rotation
 * (least recently run first) then picks the fresh ones up first.
 */
class QueryRotation
{
    /** [template, language, group] — "{city}" is replaced with the Arabic or English city name. */
    private const TEMPLATES = [
        ['شركة برمجيات {city}', 'ar', 'discovery'],
        ['شركة تطوير مواقع {city}', 'ar', 'discovery'],
        ['شركة تطوير تطبيقات {city}', 'ar', 'discovery'],
        ['شركة حلول تقنية {city}', 'ar', 'discovery'],
        ['شركة تقنية معلومات {city}', 'ar', 'discovery'],
        ['شركة تصميم وبرمجة مواقع {city}', 'ar', 'discovery'],
        ['software company {city}', 'en', 'discovery'],
        ['software house {city}', 'en', 'discovery'],
        ['web development company {city}', 'en', 'discovery'],
        ['mobile app development company {city}', 'en', 'discovery'],
        ['IT solutions company {city}', 'en', 'discovery'],
        ['digital agency {city} Saudi Arabia', 'en', 'discovery'],
        ['مطلوب مطور ويب {city}', 'ar', 'hiring'],
        ['وظائف مبرمجين {city}', 'ar', 'hiring'],
        ['مطلوب مطور Laravel {city}', 'ar', 'hiring'],
        ['وظيفة مطور React {city}', 'ar', 'hiring'],
        ['PHP developer jobs {city}', 'en', 'hiring'],
        ['Laravel developer job {city}', 'en', 'hiring'],
        ['React developer hiring {city}', 'en', 'hiring'],
        ['full stack developer {city} hiring', 'en', 'hiring'],
    ];

    public function __construct(private readonly AnalysisBacklog $backlog) {}

    /** @return int number of queries added */
    public function expandIfNeeded(int $budget): int
    {
        if ($this->backlog->isPoolFull()) {
            return 0;
        }

        $fresh = SearchQuery::query()->active()->whereNull('last_run_at')->count();
        $wanted = $budget - $fresh;
        if ($wanted <= 0) {
            return 0;
        }

        // Cities with the fewest queries first → spreads the search across the country.
        $perCity = SearchQuery::query()->whereNotNull('city')
            ->select('city', DB::raw('count(*) as n'))->groupBy('city')->pluck('n', 'city');
        $cities = collect(config('outreach.cities'))->keys()
            ->sortBy(fn (string $key) => [$perCity[$key] ?? 0, $key])->values();

        $existing = SearchQuery::query()->get(['query', 'language'])
            ->map(fn (SearchQuery $q) => mb_strtolower($q->query).'|'.$q->language)->flip();

        $added = 0;
        // Round-robin: each pass gives every city its next missing variation.
        do {
            $addedThisPass = 0;
            foreach ($cities as $key) {
                foreach (self::TEMPLATES as [$template, $language, $group]) {
                    $query = str_replace('{city}', config("outreach.cities.{$key}.{$language}"), $template);
                    $id = mb_strtolower($query).'|'.$language;
                    if (isset($existing[$id])) {
                        continue;
                    }

                    SearchQuery::query()->create([
                        'query' => $query,
                        'group' => QueryGroup::from($group),
                        'language' => $language,
                        'city' => $key,
                        'is_active' => true,
                    ]);
                    $existing[$id] = true;
                    $addedThisPass++;

                    if (++$added >= $wanted) {
                        return $added;
                    }

                    continue 2; // next city
                }
            }
        } while ($addedThisPass > 0);

        return $added;
    }
}
