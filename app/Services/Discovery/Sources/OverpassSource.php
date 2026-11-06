<?php

namespace App\Services\Discovery\Sources;

use App\Contracts\CompanySourceInterface;
use App\DTO\DiscoveredCompanyDTO;
use App\Enums\CompanySource;
use App\Models\SearchRun;
use App\Services\Discovery\BlockedDomainMatcher;
use App\Services\Discovery\CityDetector;
use App\Services\Discovery\DomainNormalizer;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenStreetMap via the public Overpass API (free, no key).
 * Usage policy: one query per day — the response is cached ~20 h — with a server-side timeout.
 */
class OverpassSource implements CompanySourceInterface
{
    public const CACHE_KEY = 'overpass:sa:elements';

    /** Office tags that are tech by definition vs. ones that need a tech-looking name. */
    private const NAME_REGEX = 'soft|tech|digital|system|solution|web|app|data|cloud|cyber|smart|code|dev|it |'
        .'برمج|تقني|تقنية|حلول|رقمي|رقمية|تطبيقات|أنظمة|انظمة|اتصالات|معلومات|سحاب|ذكي';

    /** City centers for coordinate fallback: key => [lat, lon]. */
    private const CITY_CENTERS = [
        'riyadh' => [24.7136, 46.6753], 'jeddah' => [21.4858, 39.1925], 'dammam' => [26.4207, 50.0888],
        'khobar' => [26.2794, 50.2083], 'makkah' => [21.3891, 39.8579], 'madinah' => [24.5247, 39.5692],
        'abha' => [18.2164, 42.5053], 'tabuk' => [28.3835, 36.5662], 'qassim' => [26.3260, 43.9750],
    ];

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly DomainNormalizer $domains,
        private readonly BlockedDomainMatcher $blocked,
        private readonly CityDetector $cities,
    ) {}

    public function name(): string
    {
        return CompanySource::Overpass->value;
    }

    public function isEnabled(): bool
    {
        return filled(config('outreach.overpass.url'));
    }

    public function discover(SearchRun $run): Collection
    {
        $stats = ['from_cache' => $this->cache->has(self::CACHE_KEY), 'elements' => 0, 'candidates' => 0, 'without_contact' => 0];

        try {
            $elements = $this->elements();
        } catch (Throwable $e) {
            $run->addError("overpass: {$e->getMessage()}");
            Log::warning('Overpass failed', ['error' => $e->getMessage()]);
            $run->stats = [...($run->stats ?? []), $this->name() => $stats + ['error' => true]];

            return collect();
        }

        $found = collect();
        foreach ($elements as $element) {
            $stats['elements']++;
            if (($dto = $this->toCandidate($element)) === null) {
                continue;
            }
            if ($dto->website === null && $dto->phones === [] && $dto->emails === [] && $dto->whatsapps === []) {
                $stats['without_contact']++;

                continue; // nothing to enrich or contact — skip
            }
            $found->push($dto);
            $stats['candidates']++;
        }

        $run->stats = [...($run->stats ?? []), $this->name() => $stats];

        return $found;
    }

    public function query(): string
    {
        $timeout = (int) config('outreach.overpass.timeout', 90);
        $regex = self::NAME_REGEX;

        return <<<QL
[out:json][timeout:{$timeout}];
area["ISO3166-1"="SA"][admin_level=2]->.sa;
(
  nwr["office"="it"]["name"](area.sa);
  nwr["office"~"^(company|telecommunication)$"]["name"~"{$regex}",i](area.sa);
);
out center tags;
QL;
    }

    /** @return list<array> raw OSM elements (cached ~20 h: one live query per day) */
    private function elements(): array
    {
        if ($this->cache->has(self::CACHE_KEY)) {
            return (array) $this->cache->get(self::CACHE_KEY);
        }

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->withUserAgent((string) config('outreach.crawler.user_agent'))
            ->timeout((int) config('outreach.overpass.timeout', 90) + 30)
            ->retry(
                [10000, 30000],
                when: fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true)),
                throw: false,
            )
            ->post((string) config('outreach.overpass.url'), ['data' => $this->query()]);

        if ($response->failed() || ! is_array($response->json('elements'))) {
            throw new \RuntimeException("Overpass HTTP {$response->status()}");
        }

        $elements = $response->json('elements');
        $this->cache->put(self::CACHE_KEY, $elements, now()->addHours(20));

        return $elements;
    }

    private function toCandidate(array $element): ?DiscoveredCompanyDTO
    {
        $tags = $element['tags'] ?? [];
        $name = trim((string) ($tags['name:ar'] ?? $tags['name'] ?? $tags['name:en'] ?? ''));
        if ($name === '') {
            return null;
        }

        $website = $this->firstValue($tags, ['website', 'contact:website', 'url']);
        if ($website !== null && ! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.ltrim($website, '/');
        }
        $domain = $this->domains->fromUrl($website);
        if ($domain !== null && $this->blocked->isBlocked($domain)) {
            // e.g. website tag pointing at a Facebook page — keep the company, drop the "website".
            $website = $domain = null;
        }

        $phones = $this->splitValues($tags, ['phone', 'contact:phone', 'mobile', 'contact:mobile']);
        $whatsapps = $this->splitValues($tags, ['contact:whatsapp', 'whatsapp']);
        $emails = $this->splitValues($tags, ['email', 'contact:email']);

        $cityKey = $this->cities->detect(implode(' ', array_filter([
            $tags['addr:city'] ?? null, $tags['addr:city:en'] ?? null, $tags['addr:city:ar'] ?? null, $tags['is_in:city'] ?? null,
        ]))) ?? $this->nearestCity($element);

        $osmUrl = isset($element['type'], $element['id']) ? "https://www.openstreetmap.org/{$element['type']}/{$element['id']}" : null;

        return new DiscoveredCompanyDTO(
            name: $name,
            source: CompanySource::Overpass,
            website: $domain ? $this->domains->homepage($website) : null,
            domain: $domain,
            city: $this->cities->arabicName($cityKey),
            snippet: trim(implode(' · ', array_filter([
                'OpenStreetMap office='.($tags['office'] ?? '?'),
                $tags['name:en'] ?? null,
                $tags['description'] ?? null,
                $osmUrl,
            ]))),
            phones: $phones,
            emails: $emails,
            whatsapps: $whatsapps,
        );
    }

    /** Nearest configured city within 80 km of the element's coordinates. */
    private function nearestCity(array $element): ?string
    {
        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lon = $element['lon'] ?? $element['center']['lon'] ?? null;
        if ($lat === null || $lon === null) {
            return null;
        }

        $best = null;
        $bestKm = 80.0;
        foreach (self::CITY_CENTERS as $key => [$cLat, $cLon]) {
            $km = $this->haversineKm((float) $lat, (float) $lon, $cLat, $cLon);
            if ($km < $bestKm) {
                [$best, $bestKm] = [$key, $km];
            }
        }

        return $best;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function firstValue(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($tags[$key] ?? null)) {
                return trim(explode(';', (string) $tags[$key])[0]);
            }
        }

        return null;
    }

    /** OSM allows several values separated by ";". */
    private function splitValues(array $tags, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            foreach (explode(';', (string) ($tags[$key] ?? '')) as $value) {
                if (($value = trim($value)) !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
