<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Enrichment\CompanyEnricher;
use Illuminate\Console\Command;

class EnrichCommand extends Command
{
    protected $signature = 'outreach:enrich
                            {company? : Company id (omit with --pending)}
                            {--pending : Queue every company still in status "new"}';

    protected $description = 'Crawl a company website for phones, emails, WhatsApp and text (runs now and prints the result)';

    public function handle(CompanyEnricher $enricher): int
    {
        if ($this->option('pending')) {
            $ids = Company::query()->where('status', CompanyStatus::New)->pluck('id');
            $ids->each(fn (int $id) => EnrichCompanyJob::dispatch($id));
            $this->info("Queued {$ids->count()} companies on the \"crawl\" queue.");

            return self::SUCCESS;
        }

        $company = Company::query()->find($this->argument('company'));
        if ($company === null) {
            $this->error('Company not found. Usage: outreach:enrich {id} | --pending');

            return self::FAILURE;
        }

        $this->info("Crawling {$company->name} — {$company->website}");
        $summary = $enricher->enrich($company);
        $company->refresh()->load('contacts');

        $this->table(['url', 'status'], array_map(fn ($p) => [$p['url'], $p['status']], $company->enrichment['pages'] ?? []));
        $this->table(['type', 'value', 'label', 'mobile', 'whatsapp'], $company->contacts->map(fn ($c) => [
            $c->type->value, $c->value, $c->label->value, $c->is_mobile ? 'yes' : '', $c->is_whatsapp ? 'yes' : '',
        ])->all());

        $this->line('Name:      '.$company->name);
        $this->line('LinkedIn:  '.($company->linkedin_url ?? '-'));
        $this->line('Meta:      '.mb_substr((string) $company->meta_description, 0, 120));
        $this->line('Text:      '.mb_strlen((string) $company->website_text).' chars');
        foreach ($summary['errors'] as $error) {
            $this->warn('! '.$error);
        }

        return self::SUCCESS;
    }
}
