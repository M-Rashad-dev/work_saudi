<?php

use App\Services\Discovery\BlockedDomainMatcher;
use App\Services\Discovery\CityDetector;
use App\Services\Discovery\DomainNormalizer;
use App\Services\Discovery\NameNormalizer;

describe('DomainNormalizer', function () {
    test('reduces URLs to the registrable domain', function (string $input, ?string $expected) {
        expect((new DomainNormalizer)->fromUrl($input))->toBe($expected);
    })->with([
        ['https://www.acme.com/about', 'acme.com'],
        ['http://WWW.Acme.COM:8080', 'acme.com'],
        ['https://jobs.careers.acme.com/x?y=1', 'acme.com'],
        ['https://www.acme.com.sa/ar/contact', 'acme.com.sa'],
        ['https://portal.acme.net.sa', 'acme.net.sa'],
        ['https://moe.gov.sa/ar', 'moe.gov.sa'],
        ['acme.sa', 'acme.sa'],
        ['//cdn.acme.io/a.js', 'acme.io'],
        ['https://www2.acme.co.uk', 'acme.co.uk'],
        ['https://192.168.1.10/admin', null],
        ['http://localhost:8000', null],
        ['', null],
        ['not a url', null],
    ]);

    test('builds a homepage URL, collapsing subdomains but keeping www', function () {
        $d = new DomainNormalizer;

        expect($d->homepage('https://careers.acme.com.sa/jobs/12'))->toBe('https://acme.com.sa')
            ->and($d->homepage('https://www.acme.com/ar/about'))->toBe('https://www.acme.com')
            ->and($d->homepage('http://acme.sa/x'))->toBe('http://acme.sa');
    });
});

describe('NameNormalizer', function () {
    test('folds Arabic letters, diacritics and legal suffixes', function (string $a, string $b) {
        $n = new NameNormalizer;
        expect($n->normalize($a))->toBe($n->normalize($b));
    })->with([
        ['شركةُ أكمي للتقنية المحدودة', 'شركه اكمي للتقنيه'],
        ['مؤسسة إبداع البرمجيات', 'ابداع البرمجيات'],
        ['Acme Tech LLC', 'ACME tech'],
        ['Acme Tech Co. Ltd.', 'acme-tech'],
        ['تقنيـــة ١٢٣', 'تقنيه 123'],
    ]);

    test('never returns an empty string', function () {
        expect((new NameNormalizer)->normalize('Company LLC'))->toBe('company llc');
    });

    test('different companies stay different', function () {
        $n = new NameNormalizer;
        expect($n->normalize('Acme Soft'))->not->toBe($n->normalize('Acme Systems'));
    });
});

describe('CityDetector', function () {
    test('detects cities in Arabic and English text', function (string $text, ?string $expected) {
        expect(app(CityDetector::class)->detect($text))->toBe($expected);
    })->with([
        ['شركة برمجيات في الرياض', 'riyadh'],
        ['مقرنا بالرياض وجدة', 'riyadh'],
        ['Software house based in Jeddah, Saudi Arabia', 'jeddah'],
        ['Office in Al Khobar', 'khobar'],
        ['مطلوب مطور بخبرة 3 سنوات', null],         // "خبرة" must not match "الخبر"
        ['خبرة في الخبرة البرمجية', null],
        ['Located in Buraidah, Qassim', 'qassim'],
        ['مكة المكرمة', 'makkah'],
        ['Saudi Arabia', null],
    ]);

    test('maps keys to Arabic display names', function () {
        expect(app(CityDetector::class)->arabicName('jeddah'))->toBe('جدة')
            ->and(app(CityDetector::class)->arabicName(null))->toBeNull();
    });
});

describe('BlockedDomainMatcher', function () {
    $matcher = fn () => new BlockedDomainMatcher([
        'gov.sa' => 'gov', 'google.com' => 'reference', 'x.com' => 'social', 'linkedin.com' => 'job_board',
    ]);

    test('matches on label boundaries only', function () use ($matcher) {
        $m = $matcher();

        expect($m->isBlocked('moe.gov.sa'))->toBeTrue()
            ->and($m->isBlocked('gov.sa'))->toBeTrue()
            ->and($m->isBlocked('play.google.com'))->toBeTrue()
            ->and($m->isBlocked('x.com'))->toBeTrue()
            ->and($m->isBlocked('box.com'))->toBeFalse()
            ->and($m->isBlocked('notgoogle.com'))->toBeFalse()
            ->and($m->isBlocked('acme.com.sa'))->toBeFalse()
            ->and($m->isBlocked(null))->toBeFalse();
    });

    test('identifies job boards separately', function () use ($matcher) {
        $m = $matcher();

        expect($m->isJobBoard('sa.linkedin.com'))->toBeTrue()
            ->and($m->isJobBoard('moe.gov.sa'))->toBeFalse()
            ->and($m->category('moe.gov.sa'))->toBe('gov');
    });
});
