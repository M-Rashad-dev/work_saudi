<?php

namespace App\Http\Controllers;

use App\DTO\DiscoveredCompanyDTO;
use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Enums\ContactType;
use App\Http\Presenters\CompanyPresenter;
use App\Http\Requests\Outreach\StoreCompanyRequest;
use App\Jobs\AnalyzeCompanyJob;
use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Discovery\CompanyUpserter;
use App\Services\Enrichment\ContactRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "الشركات": all companies with filters, a detail page, and manual add. */
class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'source' => ['nullable', Rule::enum(CompanySource::class)],
            'min_relevance' => ['nullable', 'integer', 'between:0,100'],
            'has_whatsapp' => ['nullable', 'boolean'],
        ]);

        $companies = Company::query()
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$term}%")->orWhere('domain', 'like', "%{$term}%")))
            ->when($filters['city'] ?? null, fn (Builder $q, string $city) => $q->where('city', $city))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when(isset($filters['min_relevance']), fn (Builder $q) => $q->where('relevance', '>=', (int) $filters['min_relevance']))
            ->when(($filters['has_whatsapp'] ?? null) !== null, fn (Builder $q) => $filters['has_whatsapp']
                ? $q->hasWhatsapp()
                : $q->whereNot(fn (Builder $q) => $q->hasWhatsapp()))
            ->withCount('contacts')
            ->withExists(['contacts as has_whatsapp' => fn (Builder $q) => $q->where('type', ContactType::Phone)
                ->where(fn (Builder $q) => $q->where('is_whatsapp', true)->orWhere('is_mobile', true))])
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'domain' => $c->domain,
                'city' => $c->city,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'source_label' => $c->source->label(),
                'relevance' => $c->relevance,
                'is_hiring_devs' => $c->is_hiring_devs,
                'has_whatsapp' => (bool) $c->has_whatsapp,
                'contacts_count' => $c->contacts_count,
                'created_at' => $c->created_at->toDateString(),
            ]);

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'filters' => $filters,
            'options' => [
                'cities' => Company::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
                'statuses' => CompanyStatus::options(),
                'sources' => CompanySource::options(),
            ],
            'counts' => Company::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Company $company, CompanyPresenter $presenter): Response
    {
        $company->load(['contacts', 'batchItem.batch']);

        $history = collect([
            ['at' => $company->created_at, 'text' => 'تم اكتشافها عن طريق: '.$company->source->label()],
            $company->enriched_at ? ['at' => $company->enriched_at, 'text' => 'تم جمع بيانات الموقع ('.count($company->enrichment['pages'] ?? []).' صفحة)'] : null,
            $company->analyzed_at ? ['at' => $company->analyzed_at, 'text' => 'تم التحليل بواسطة: '.($company->llm_driver ?? 'بدون تحليل').' — '.$company->status->label()] : null,
            $company->batchItem ? ['at' => $company->batchItem->created_at, 'text' => 'ظهرت في دفعة '.$company->batchItem->batch->date->toDateString()] : null,
            $company->batchItem?->action_at ? ['at' => $company->batchItem->action_at, 'text' => 'الإجراء: '.$company->batchItem->action->label()] : null,
        ])->filter()->sortBy('at')->map(fn ($e) => ['at' => $e['at']->locale('ar')->translatedFormat('j F Y H:i'), 'text' => $e['text']])->values();

        return Inertia::render('Companies/Show', [
            'company' => $presenter->summary($company) + $presenter->buttons($company) + [
                'status' => $company->status->value,
                'status_label' => $company->status->label(),
                'source_label' => $company->source->label(),
                'source_snippet' => $company->source_snippet,
                'meta_description' => $company->meta_description,
                'llm_driver' => $company->llm_driver,
                'pages' => $company->enrichment['pages'] ?? [],
                'enrichment_errors' => $company->enrichment['errors'] ?? [],
                'batch_item' => $company->batchItem ? [
                    'id' => $company->batchItem->id,
                    'date' => $company->batchItem->batch->date->toDateString(),
                    'action' => $company->batchItem->action->value,
                    'action_label' => $company->batchItem->action->label(),
                    'note' => $company->batchItem->note,
                ] : null,
            ],
            'history' => $history,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Companies/Create', [
            'cities' => collect(config('outreach.cities'))->pluck('ar')->values(),
        ]);
    }

    public function store(StoreCompanyRequest $request, CompanyUpserter $upserter, ContactRecorder $contacts): RedirectResponse
    {
        $data = $request->validated();

        [$company, $created] = $upserter->upsert(new DiscoveredCompanyDTO(
            name: $data['name'],
            source: CompanySource::Manual,
            website: $data['website'] ?? null,
            city: $data['city'] ?? null,
            snippet: $data['note'] ?? null,
            jobPostUrl: $data['job_post_url'] ?? null,
            isHiringSignal: filled($data['job_post_url'] ?? null),
            phones: array_filter([$data['phone'] ?? null]),
            emails: array_filter([$data['email'] ?? null]),
            whatsapps: array_filter([$data['whatsapp'] ?? null]),
        ));
        $added = $contacts->recordFromDiscovery($company, new DiscoveredCompanyDTO(
            name: $company->name,
            source: CompanySource::Manual,
            phones: array_filter([$data['phone'] ?? null]),
            emails: array_filter([$data['email'] ?? null]),
            whatsapps: array_filter([$data['whatsapp'] ?? null]),
        ));

        if (! $created) {
            return to_route('companies.show', $company)
                ->with('error', 'الشركة موجودة بالفعل'.($added ? " — تمت إضافة {$added} وسيلة تواصل جديدة." : '.'));
        }

        // Crawl (if it has a site) and analyze even if the pool is full: you added it on purpose.
        EnrichCompanyJob::dispatch($company->id, forceAnalysis: true);

        return to_route('companies.show', $company)->with('success', 'تمت إضافة الشركة. جاري جمع البيانات والتحليل في الخلفية.');
    }

    public function enrich(Company $company): RedirectResponse
    {
        EnrichCompanyJob::dispatch($company->id, forceAnalysis: $company->status !== CompanyStatus::Shown);

        return back()->with('success', 'جاري إعادة جمع البيانات من الموقع…');
    }

    public function analyze(Company $company): RedirectResponse
    {
        if ($company->status === CompanyStatus::Shown) {
            return back()->with('error', 'الشركة ظهرت في دفعة سابقة — لا حاجة لإعادة التحليل.');
        }

        AnalyzeCompanyJob::dispatch($company->id, force: true);

        return back()->with('success', 'جاري إعادة التحليل…');
    }
}
