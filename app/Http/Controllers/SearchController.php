<?php

namespace App\Http\Controllers;

use App\Enums\QueryGroup;
use App\Http\Requests\Outreach\SearchQueryRequest;
use App\Models\SearchQuery;
use App\Models\SearchRun;
use App\Services\Analysis\AnalysisBacklog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** "البحث": manage search queries and inspect recent runs. */
class SearchController extends Controller
{
    public function index(AnalysisBacklog $backlog): Response
    {
        $cities = config('outreach.cities');

        return Inertia::render('Search/Index', [
            'queries' => SearchQuery::query()->orderBy('group')->orderBy('city')->orderBy('query')->get()
                ->map(fn (SearchQuery $q) => [
                    'id' => $q->id,
                    'query' => $q->query,
                    'group' => $q->group->value,
                    'group_label' => $q->group->label(),
                    'language' => $q->language,
                    'city' => $q->city,
                    'city_label' => $q->city ? ($cities[$q->city]['ar'] ?? $q->city) : 'كل السعودية',
                    'is_active' => $q->is_active,
                    'last_run_at' => $q->last_run_at?->locale('ar')->diffForHumans(),
                    'results_count' => $q->results_count,
                ]),
            'runs' => SearchRun::query()->latest('started_at')->limit(20)->get()->map(fn (SearchRun $run) => [
                'id' => $run->id,
                'type' => $run->type->value,
                'type_label' => $run->type->label(),
                'started_at' => $run->started_at->locale('ar')->translatedFormat('j F H:i'),
                'duration' => $run->finished_at ? $run->started_at->diffForHumans($run->finished_at, true) : null,
                'running' => $run->finished_at === null,
                'stats' => collect($run->stats ?? [])->except('new_company_ids'),
                'errors' => collect($run->errors ?? [])->pluck('message'),
            ]),
            'groups' => QueryGroup::options(),
            'cities' => collect($cities)->map(fn ($c, $key) => ['value' => $key, 'label' => $c['ar']])->values(),
            'pool' => ['size' => $backlog->poolSize(), 'target' => $backlog->poolTarget()],
        ]);
    }

    public function store(SearchQueryRequest $request): RedirectResponse
    {
        SearchQuery::query()->create($request->validated() + ['is_active' => true]);

        return back()->with('success', 'تمت إضافة جملة البحث.');
    }

    public function update(SearchQueryRequest $request, SearchQuery $searchQuery): RedirectResponse
    {
        $searchQuery->update($request->validated());

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroy(SearchQuery $searchQuery): RedirectResponse
    {
        $searchQuery->delete();

        return back()->with('success', 'تم حذف جملة البحث.');
    }
}
