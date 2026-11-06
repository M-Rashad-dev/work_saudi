<?php

namespace App\Http\Controllers;

use App\Http\Requests\Outreach\StoreBlockedDomainRequest;
use App\Http\Requests\Outreach\UpdateSettingsRequest;
use App\Models\BlockedDomain;
use App\Services\Analysis\CompanyAnalyzer;
use App\Services\Batch\MessageRenderer;
use App\Services\Discovery\SourceState;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** "الإعدادات": message templates, batch rules, blocked domains, AI driver order, sources. */
class SettingsController extends Controller
{
    private const DRIVER_LABELS = [
        'gemini' => 'Gemini (مجاني من Google AI Studio)',
        'ollama' => 'Ollama (نموذج محلي على السيرفر)',
        'heuristic' => 'بدون ذكاء اصطناعي (كلمات مفتاحية)',
    ];

    public function edit(SettingsRepository $settings, CompanyAnalyzer $analyzer, SourceState $sources): Response
    {
        $available = $analyzer->availableDriverNames();

        return Inertia::render('Settings/Edit', [
            'settings' => $settings->toArray(),
            'placeholders' => MessageRenderer::PLACEHOLDERS,
            'drivers' => collect(self::DRIVER_LABELS)->map(fn ($label, $name) => [
                'name' => $name, 'label' => $label, 'available' => in_array($name, $available, true),
            ])->values(),
            'blockedDomains' => BlockedDomain::query()->orderBy('category')->orderBy('domain')->get(['id', 'domain', 'category']),
            'sources' => [
                'serper' => [
                    'configured' => filled(config('outreach.serper.api_key')),
                    'disabled' => $sources->info('serper'),
                ],
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $data = $request->validated();
        $data['cv_url'] ??= '';
        $data['portfolio_url'] ??= '';
        $data['batch_size'] = (int) $data['batch_size'];
        $data['min_relevance'] = (int) $data['min_relevance'];
        $data['llm_drivers'] = array_values($data['llm_drivers']);

        $settings->setMany($data);

        return back()->with('success', 'تم حفظ الإعدادات.');
    }

    public function storeBlockedDomain(StoreBlockedDomainRequest $request): RedirectResponse
    {
        BlockedDomain::query()->create($request->validated() + ['category' => 'other']);

        return back()->with('success', 'تمت إضافة النطاق للقائمة المحظورة.');
    }

    public function destroyBlockedDomain(BlockedDomain $blockedDomain): RedirectResponse
    {
        $blockedDomain->delete();

        return back()->with('success', 'تم حذف النطاق من القائمة المحظورة.');
    }

    public function enableSource(string $source, SourceState $sources): RedirectResponse
    {
        abort_unless(in_array($source, ['serper'], true), 404);
        $sources->enable($source);

        return back()->with('success', "تم تفعيل {$source} مرة أخرى.");
    }
}
