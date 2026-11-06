<?php

namespace App\Services\Analysis;

use App\DTO\CompanyAnalysisInput;

/** The system + user prompt shared by every LLM driver. */
class AnalysisPrompt
{
    public function system(): string
    {
        return <<<'TXT'
You analyze companies in Saudi Arabia for a Full Stack Web Developer (Laravel + React, 4 years) who is looking for a job.
Use ONLY the facts in the input. Do not invent services, technologies or cities.

Reply with ONLY one JSON object, no markdown, exactly these keys:
{
  "is_software_company": boolean,   // builds software / web / mobile apps / SaaS / IT solutions as its core business
  "is_hiring_devs": boolean,        // clear evidence it is hiring developers now (job post, careers page with dev roles)
  "relevance": integer 0-100,       // how likely they could hire a Laravel/React developer
  "description_ar": string,         // 2-3 sentences in Arabic: what the company does
  "services": [string],             // main services, short, Arabic preferred
  "tech_hints": [string],           // technologies explicitly mentioned, e.g. "Laravel", "React", "PHP"
  "city": string or null,           // Saudi city in Arabic (e.g. "الرياض") if stated, else null
  "reason_ar": string               // very short Arabic reason, e.g. "شركة برمجيات" or "بتوظف مطور Laravel"
}

Relevance guide: software house / web & app agency / SaaS hiring developers = 80-100; software company = 60-85;
IT company mostly hardware, networks or resale = 25-50; non-tech business = 0-20.
TXT;
    }

    public function user(CompanyAnalysisInput $input): string
    {
        $lines = [
            'Company name: '.$input->name,
            'Website: '.($input->website ?? 'unknown'),
            'Known city: '.($input->city ?? 'unknown'),
        ];
        if ($input->jobPostUrl) {
            $lines[] = 'Job post URL: '.$input->jobPostUrl;
        }
        if ($input->searchSnippet) {
            $lines[] = "Search result / job post snippet:\n".$input->searchSnippet;
        }
        if ($input->metaDescription) {
            $lines[] = 'Meta description: '.$input->metaDescription;
        }
        $lines[] = "Website text (truncated):\n".($input->websiteText !== '' ? $input->websiteText : '(not available)');

        return implode("\n\n", $lines);
    }

    public function retryNote(string $error): string
    {
        return "\n\nYour previous reply was invalid ({$error}). Reply again with ONLY the JSON object, exactly the keys above.";
    }
}
