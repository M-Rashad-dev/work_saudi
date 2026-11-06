<?php

namespace Database\Seeders;

use App\Models\BlockedDomain;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class BlockedDomainSeeder extends Seeder
{
    /**
     * Domains are matched as suffixes (Phase 2): "gov.sa" blocks every *.gov.sa,
     * "google.com" blocks play.google.com, etc.
     * Job boards are NOT skipped silently — Phase 2 extracts the hiring company from them.
     */
    private const DOMAINS = [
        'job_board' => [
            'linkedin.com', 'bayt.com', 'indeed.com', 'glassdoor.com', 'naukrigulf.com', 'gulftalent.com',
            'wuzzuf.net', 'tanqeeb.com', 'laimoon.com', 'monstergulf.com', 'akhtaboot.com', 'careerjet.com.sa',
            'jooble.org', 'drjobpro.com', 'expatriates.com', 'mourjan.com', 'opensooq.com', 'haraj.com.sa',
            'jobzaty.com', 'wadhefa.com', 'ewdifh.com', 'saudiyajobs.com', 'jobs.sa', 'talent.com',
            'wellfound.com', 'remoteok.com', 'jobrapido.com', 'adzuna.com', 'whatjobs.com', 'jora.com',
        ],
        'directory' => [
            'yellowpages.com.sa', 'saudiayp.com', 'saudi-yp.com', 'yellowpages.com', 'daleeli.com', 'yelp.com',
            'clutch.co', 'goodfirms.co', 'designrush.com', 'sortlist.com', 'themanifest.com', 'crunchbase.com',
            'zoominfo.com', 'dnb.com', 'kompass.com', 'cybo.com', 'top-rated.online', 'foursquare.com',
            'tripadvisor.com', 'waze.com', 'infobel.com', 'opencorporates.com', 'magnitt.com', 'f6s.com',
            'tracxn.com', 'pitchbook.com', 'owler.com', 'rocketreach.co', 'apollo.io', 'signalhire.com',
            'ensun.io', 'techbehemoths.com', 'topdevelopers.co', 'mobileappdaily.com', 'upwork.com',
            'fiverr.com', 'freelancer.com', 'khamsat.com', 'mostaql.com', 'businesslist.com.sa', 'saudicompanies.net',
        ],
        'social' => [
            'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'youtube.com', 'tiktok.com', 'snapchat.com',
            'pinterest.com', 'reddit.com', 'quora.com', 't.me', 'telegram.me', 'github.com', 'gitlab.com',
            'medium.com', 'behance.net', 'dribbble.com', 'threads.net', 'wa.me', 'whatsapp.com',
        ],
        'news' => [
            'argaam.com', 'sabq.org', 'okaz.com.sa', 'alarabiya.net', 'aleqt.com', 'arabnews.com',
            'saudigazette.com.sa', 'aawsat.com', 'cnbcarabia.com', 'alriyadh.com', 'al-madina.com',
            'almowaten.net', 'zawya.com', 'wamda.com', 'techcrunch.com', 'forbesmiddleeast.com',
            'aljazeera.net', 'skynewsarabia.com', 'alyaum.com', 'almarsd.com', 'mz-mz.net', 'ajel.sa',
        ],
        'gov' => [
            'gov.sa', 'edu.sa', 'gov', 'jadarat.sa', 'hrsd.gov.sa',
        ],
        'reference' => [
            'wikipedia.org', 'wikiwand.com', 'britannica.com', 'google.com', 'bing.com', 'duckduckgo.com',
            'yahoo.com', 'apple.com', 'microsoft.com', 'amazon.com', 'amazon.sa', 'noon.com', 'mawdoo3.com',
            'stackoverflow.com', 'w3schools.com', 'udemy.com', 'coursera.org', 'youtu.be', 'blogspot.com',
            'wordpress.com', 'wixsite.com', 'tumblr.com', 'scribd.com', 'slideshare.net', 'pdfcoffee.com',
        ],
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::DOMAINS as $category => $domains) {
            foreach ($domains as $domain) {
                $rows[] = ['domain' => $domain, 'category' => $category, 'created_at' => now(), 'updated_at' => now()];
            }
        }

        BlockedDomain::query()->upsert($rows, ['domain'], ['category', 'updated_at']);
        Cache::forget(BlockedDomain::CACHE_KEY);
    }
}
