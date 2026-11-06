<?php

namespace Database\Seeders;

use App\Enums\QueryGroup;
use App\Models\SearchQuery;
use Illuminate\Database\Seeder;

class SearchQuerySeeder extends Seeder
{
    /**
     * [query, language, city key (config('outreach.cities')) or null for nationwide]
     * Big cities get more queries; rotation (least-recently-run first) spreads them across days.
     */
    private const DISCOVERY = [
        // Riyadh
        ['شركة برمجيات الرياض', 'ar', 'riyadh'],
        ['شركة تطوير مواقع الرياض', 'ar', 'riyadh'],
        ['software company Riyadh', 'en', 'riyadh'],
        ['mobile app development company Riyadh', 'en', 'riyadh'],
        // Jeddah
        ['شركة تطوير تطبيقات جدة', 'ar', 'jeddah'],
        ['شركة برمجيات جدة', 'ar', 'jeddah'],
        ['IT solutions company Jeddah', 'en', 'jeddah'],
        ['software house Jeddah', 'en', 'jeddah'],
        // Dammam
        ['شركة برمجيات الدمام', 'ar', 'dammam'],
        ['شركة تطوير تطبيقات الدمام', 'ar', 'dammam'],
        ['web development company Dammam', 'en', 'dammam'],
        ['software company Dammam', 'en', 'dammam'],
        // Khobar
        ['شركة حلول تقنية الخبر', 'ar', 'khobar'],
        ['شركة تطوير مواقع الخبر', 'ar', 'khobar'],
        ['software development company Khobar', 'en', 'khobar'],
        // Makkah
        ['شركة برمجيات مكة', 'ar', 'makkah'],
        ['شركة تصميم وبرمجة تطبيقات مكة', 'ar', 'makkah'],
        ['software company Makkah', 'en', 'makkah'],
        // Madinah
        ['شركة برمجيات المدينة المنورة', 'ar', 'madinah'],
        ['شركة تطوير مواقع المدينة المنورة', 'ar', 'madinah'],
        ['IT company Madinah', 'en', 'madinah'],
        // Abha
        ['شركة برمجيات أبها', 'ar', 'abha'],
        ['software company Abha', 'en', 'abha'],
        // Tabuk
        ['شركة حلول تقنية تبوك', 'ar', 'tabuk'],
        ['IT solutions company Tabuk', 'en', 'tabuk'],
        // Qassim
        ['شركة برمجيات القصيم بريدة', 'ar', 'qassim'],
        ['software company Buraidah Qassim', 'en', 'qassim'],
    ];

    private const HIRING = [
        ['مطلوب مطور Laravel السعودية', 'ar', null],
        ['وظائف مطور ويب السعودية', 'ar', null],
        ['full stack developer job Saudi Arabia', 'en', null],
        ['hiring PHP developer Riyadh', 'en', 'riyadh'],
        ['وظيفة مبرمج Backend الرياض', 'ar', 'riyadh'],
        ['وظيفة مطور React الرياض', 'ar', 'riyadh'],
        ['Laravel developer job Riyadh', 'en', 'riyadh'],
        ['"React developer" Jeddah hiring', 'en', 'jeddah'],
        ['مطلوب مبرمج PHP جدة', 'ar', 'jeddah'],
        ['hiring full stack developer Jeddah', 'en', 'jeddah'],
        ['وظائف مبرمجين الدمام', 'ar', 'dammam'],
        ['backend developer jobs Dammam', 'en', 'dammam'],
        ['مطلوب مطور Full Stack الخبر', 'ar', 'khobar'],
        ['وظيفة مطور تطبيقات مكة', 'ar', 'makkah'],
        ['مطلوب مطور ويب المدينة المنورة', 'ar', 'madinah'],
    ];

    public function run(): void
    {
        $rows = [];
        foreach ([QueryGroup::Discovery->value => self::DISCOVERY, QueryGroup::Hiring->value => self::HIRING] as $group => $queries) {
            foreach ($queries as [$query, $language, $city]) {
                $rows[] = [
                    'query' => $query,
                    'group' => $group,
                    'language' => $language,
                    'city' => $city,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Idempotent: re-seeding adds new queries but keeps run history and on/off toggles.
        SearchQuery::query()->upsert($rows, ['query', 'language'], ['group', 'city', 'updated_at']);
    }
}
