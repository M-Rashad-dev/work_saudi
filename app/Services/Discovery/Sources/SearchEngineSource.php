<?php

namespace App\Services\Discovery\Sources;

use App\Contracts\CompanySourceInterface;
use App\DTO\DiscoveredCompanyDTO;
use App\DTO\SearchResultDTO;
use App\Enums\CompanySource;
use App\Enums\QueryGroup;
use App\Exceptions\SearchUnavailableException;
use App\Exceptions\SourceDisabledException;
use App\Models\Company;
use App\Models\SearchQuery;
use App\Models\SearchRun;
use App\Services\Discovery\BlockedDomainMatcher;
use App\Services\Discovery\CityDetector;
use App\Services\Discovery\CompanyTitleParser;
use App\Services\Discovery\DomainNormalizer;
use App\Services\Discovery\JobBoardCompanyExtractor;
use App\Services\Discovery\NameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Shared logic for web-search sources (SearXNG, Serper): query rotation, a per-run search budget,
 * polite delays, job-board handling with an "official site" lookup, and a graceful stop after
 * N consecutive failed/empty searches.
 */
abstract class SearchEngineSource implements CompanySourceInterface
{
    private const CAREERS_PATH = '#/(careers?|jobs?|vacanc|join|hiring|work-with-us|وظائف|توظيف|انضم)#iu';

    private int $budget = 0;

    private int $used = 0;

    private int $consecutiveFailures = 0;

    private bool $madeLiveCall = false;

    private bool $disabledMidRun = false;

    /** @var array<string, int> */
    protected array $stats = [];

    private SearchRun $run;

    public function __construct(
        protected readonly BlockedDomainMatcher $blocked,
        protected readonly JobBoardCompanyExtractor $jobBoards,
        protected readonly CompanyTitleParser $titles,
        protected readonly CityDetector $cities,
        protected readonly DomainNormalizer $domains,
        protected readonly NameNormalizer $names,
    ) {}

    abstract protected function companySource(): CompanySource;

    /** Max searches (HTTP calls) per run. */
    abstract protected function budget(): int;

    /** @return array{0: int, 1: int} random delay range in seconds between live searches */
    abstract protected function delayRange(): array;

    /**
     * @return list<SearchResultDTO>
     *
     * @throws SearchUnavailableException transient failure (counts toward the graceful stop)
     * @throws SourceDisabledException permanent failure (quota gone): stop this source now
     */
    abstract protected function performSearch(string $query, string $language): array;

    abstract protected function isCached(string $query, string $language): bool;

    /** True when the last performSearch() was served from cache. */
    abstract protected function lastFromCache(): bool;

    public function name(): string
    {
        return $this->companySource()->value;
    }

    public function discover(SearchRun $run): Collection
    {
        $this->run = $run;
        $this->budget = max(0, $this->budget());
        $this->used = 0;
        $this->consecutiveFailures = 0;
        $this->madeLiveCall = false;
        $this->disabledMidRun = false;
        $this->stats = array_fill_keys([
            'queries_run', 'lookups_run', 'results', 'blocked', 'job_board_hits', 'job_board_unparsed',
            'official_site_found', 'official_site_missing', 'candidates', 'errors',
        ], 0);

        $found = collect();
        $queries = SearchQuery::query()->active()->rotationOrder()->limit($this->budget)->get();

        foreach ($queries as $query) {
            if ($this->budgetLeft() === 0 || $this->shouldStop()) {
                break;
            }

            $results = $this->search($query->query, $query->language);
            $this->stats['queries_run']++;

            // A failed search keeps its place at the front of the rotation for the next run.
            if ($results !== null) {
                $query->forceFill(['last_run_at' => now(), 'results_count' => count($results)])->save();
            }

            foreach ($results ?? [] as $result) {
                $this->stats['results']++;
                if (($dto = $this->toCandidate($result, $query)) !== null) {
                    $found->push($dto);
                    $this->stats['candidates']++;
                }
            }
        }

        if (! $this->disabledMidRun && $this->consecutiveFailures >= $this->maxFailures()) {
            $message = "{$this->name()}: stopped after {$this->consecutiveFailures} consecutive failed/empty searches; will continue tomorrow.";
            $run->addError($message);
            Log::warning($message);
        }

        $run->stats = [...($run->stats ?? []), $this->name() => $this->stats + ['budget' => $this->budget, 'searches_used' => $this->used]];

        return $found;
    }

    private function toCandidate(SearchResultDTO $result, SearchQuery $query): ?DiscoveredCompanyDTO
    {
        if ($result->domain === null) {
            return null;
        }

        $queryCity = $this->cities->arabicName($query->city);
        $city = $this->cities->arabicName($this->cities->detect($result->text())) ?? $queryCity;

        if ($this->blocked->isJobBoard($result->domain)) {
            return $this->fromJobBoard($result, $city);
        }

        if ($this->blocked->isBlocked($result->domain)) {
            $this->stats['blocked']++;

            return null;
        }

        $isCareersPage = $query->group === QueryGroup::Hiring && preg_match(self::CAREERS_PATH, rawurldecode($result->url));

        return new DiscoveredCompanyDTO(
            name: $this->titles->parse($result, $result->domain),
            source: $this->companySource(),
            website: $this->domains->homepage($result->url),
            domain: $result->domain,
            city: $city,
            snippet: $result->text(),
            jobPostUrl: $isCareersPage ? $result->url : null,
            isHiringSignal: (bool) $isCareersPage,
        );
    }

    /** Job-board hit: take the company name from the post, then look up the company's own site. */
    private function fromJobBoard(SearchResultDTO $result, ?string $city): ?DiscoveredCompanyDTO
    {
        $this->stats['job_board_hits']++;

        $name = $this->jobBoards->extract($result);
        if ($name === null) {
            $this->stats['job_board_unparsed']++;

            return null;
        }

        [$website, $domain] = $this->findOfficialSite($name);
        $this->stats[$domain ? 'official_site_found' : 'official_site_missing']++;

        return new DiscoveredCompanyDTO(
            name: $name,
            source: $this->companySource(),
            website: $website,
            domain: $domain,
            city: $city,
            snippet: $result->text(),
            jobPostUrl: $result->url,
            isHiringSignal: true,
        );
    }

    /** @return array{0: ?string, 1: ?string} [homepage, domain] */
    private function findOfficialSite(string $name): array
    {
        // Already known? Skip the extra search entirely.
        $known = Company::query()
            ->where('normalized_name', $this->names->normalize($name))
            ->whereNotNull('domain')
            ->first(['website', 'domain']);
        if ($known) {
            return [$known->website, $known->domain];
        }

        if ($this->budgetLeft() === 0 || $this->shouldStop()) {
            return [null, null];
        }

        $results = $this->search("{$name} السعودية الموقع الرسمي", 'ar');
        $this->stats['lookups_run']++;

        foreach (array_slice($results ?? [], 0, 6) as $result) {
            if ($result->domain !== null && ! $this->blocked->isBlocked($result->domain) && $this->matchesName($name, $result)) {
                return [$this->domains->homepage($result->url), $result->domain];
            }
        }

        return [null, null];
    }

    private function matchesName(string $name, SearchResultDTO $result): bool
    {
        $label = str_replace('-', '', $this->domains->label($result->domain));
        foreach ($this->names->latinTokens($name) as $token) {
            if (str_contains($label, $token)) {
                return true;
            }
        }

        $normalizedName = $this->names->normalize($name);
        $normalizedTitle = $this->names->normalize($result->title);

        return mb_strlen($normalizedName) >= 3 && str_contains($normalizedTitle, $normalizedName);
    }

    /** One budgeted search. Returns null on error. */
    private function search(string $query, string $language): ?array
    {
        if ($this->madeLiveCall && ! $this->isCached($query, $language)) {
            [$min, $max] = $this->delayRange();
            if ($max > 0) {
                Sleep::for(random_int($min, max($min, $max)))->seconds();
            }
        }

        $this->used++;

        try {
            $results = $this->performSearch($query, $language);
        } catch (SourceDisabledException $e) {
            $this->disabledMidRun = true;
            $this->consecutiveFailures = PHP_INT_MAX; // stop now
            $this->stats['errors']++;
            $this->run->addError("{$this->name()}: {$e->getMessage()}");
            Log::warning("{$this->name()} disabled", ['reason' => $e->getMessage()]);

            return null;
        } catch (SearchUnavailableException $e) {
            $this->madeLiveCall = true;
            $this->consecutiveFailures++;
            $this->stats['errors']++;
            $this->run->addError("{$this->name()}: {$e->getMessage()}", ['query' => $query]);
            Log::warning("{$this->name()} search failed", ['query' => $query, 'error' => $e->getMessage()]);

            return null;
        }

        $this->madeLiveCall = $this->madeLiveCall || ! $this->lastFromCache();
        $this->consecutiveFailures = $results === [] ? $this->consecutiveFailures + 1 : 0;

        return $results;
    }

    private function budgetLeft(): int
    {
        return max(0, $this->budget - $this->used);
    }

    private function maxFailures(): int
    {
        return max(1, (int) config('outreach.searxng.max_consecutive_failures', 3));
    }

    private function shouldStop(): bool
    {
        return $this->consecutiveFailures >= $this->maxFailures();
    }
}
