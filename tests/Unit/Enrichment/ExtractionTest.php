<?php

use App\Enums\ContactLabel;
use App\Services\Enrichment\EmailExtractor;
use App\Services\Enrichment\HtmlDocument;
use App\Services\Enrichment\PhoneExtractor;
use App\Services\Enrichment\PhoneNormalizer;

function fixture(string $name, string $url = 'https://www.acme.com.sa/ar/contact'): HtmlDocument
{
    return new HtmlDocument(file_get_contents(base_path("tests/Fixtures/html/{$name}")), $url);
}

function phones(HtmlDocument $doc): array
{
    return collect(app(PhoneExtractor::class)->extract($doc))->keyBy('value')->all();
}

describe('PhoneNormalizer', function () {
    test('normalizes Saudi formats to E.164', function (string $raw, ?string $e164, ?bool $mobile = null) {
        $phone = app(PhoneNormalizer::class)->normalize($raw);

        expect($phone?->e164)->toBe($e164);
        if ($mobile !== null) {
            expect($phone->isMobile)->toBe($mobile);
        }
    })->with([
        '05 local' => ['0551234567', '+966551234567', true],
        '+966' => ['+966 55 123 4567', '+966551234567', true],
        '00966' => ['00966551234567', '+966551234567', true],
        '966 no plus' => ['966551234567', '+966551234567', true],
        '5xx no zero' => ['551234567', '+966551234567', true],
        'arabic-indic' => ['٠٥٥١٢٣٤٥٦٧', '+966551234567', true],
        'persian digits' => ['۰۵۵۱۲۳۴۵۶۷', '+966551234567', true],
        'tel: urlencoded' => ['tel:%2B966551234567', '+966551234567', true],
        'riyadh landline' => ['011 234 5678', '+966112345678', false],
        'jeddah landline' => ['(012) 6543210', '+966126543210', false],
        'unified 920' => ['920012345', '+966920012345', false],
        'too short' => ['12345', null],
        'CR number' => ['1010123456', null],
        'VAT number' => ['300123456700003', null],
    ]);
});

describe('PhoneExtractor', function () {
    test('finds every real number on an Arabic contact page and ignores noise', function () {
        $found = phones(fixture('contact-ar.html'));

        expect(array_keys($found))->toEqualCanonicalizing([
            '+966551234567', // Arabic-Indic digits
            '+966112345678', // 011 landline
            '+966920012345', // unified number
            '+966507654321', // +966
            '+966561112233', // 00966 with dashes
            '+966544445555', // HR line
            '+966581234567', // "واتساب:" in text
            '+966112223333', // tel: link
            '+966591112233', // wa.me
            '+966532221100', // api.whatsapp.com
        ]);
        // script/style numbers, CR, VAT and prices are not phones
        expect($found)->not->toHaveKeys(['+966551112222', '+966553334444']);
    });

    test('marks mobiles, landlines and WhatsApp correctly', function () {
        $found = phones(fixture('contact-ar.html'));

        expect($found['+966591112233']->isWhatsapp)->toBeTrue()
            ->and($found['+966591112233']->label)->toBe(ContactLabel::Whatsapp)
            ->and($found['+966532221100']->isWhatsapp)->toBeTrue()
            ->and($found['+966581234567']->isWhatsapp)->toBeTrue()
            ->and($found['+966551234567']->isMobile)->toBeTrue()
            ->and($found['+966551234567']->isWhatsapp)->toBeFalse()
            ->and($found['+966112345678']->isMobile)->toBeFalse()
            ->and($found['+966920012345']->isMobile)->toBeFalse()
            ->and($found['+966544445555']->label)->toBe(ContactLabel::Hr);
    });

    test('a landline in a WhatsApp link is never marked WhatsApp', function () {
        $doc = new HtmlDocument('<a href="https://wa.me/966112345678">wa</a>', 'https://a.sa/');

        expect(phones($doc)['+966112345678']->isWhatsapp)->toBeFalse();
    });

    test('adjacent numbers on one line are split correctly', function () {
        $doc = new HtmlDocument('<p>هاتف 0112345678 0551234567</p>', 'https://a.sa/');

        expect(array_keys(phones($doc)))->toEqualCanonicalizing(['+966112345678', '+966551234567']);
    });

    test('numbers on a careers page are labelled careers', function () {
        $doc = new HtmlDocument('<p>Call 0551234567</p>', 'https://a.sa/careers');
        $found = app(PhoneExtractor::class)->extract($doc, isCareersPage: true);

        expect($found[0]->label)->toBe(ContactLabel::Careers);
    });
});

describe('EmailExtractor', function () {
    test('extracts mailto, text and Cloudflare-protected emails, drops junk, HR first', function () {
        $emails = collect(app(EmailExtractor::class)->extract(fixture('contact-ar.html')));

        expect($emails->pluck('value')->all())->toBe([
            'hr@acme.com.sa',
            'people@acme.sa',          // decoded from data-cfemail; "people@" is an HR mailbox
            'careers@acme.com.sa',
            'info@acme.com.sa',
        ])->and($emails->first()->label)->toBe(ContactLabel::Hr)
            ->and($emails[2]->label)->toBe(ContactLabel::Careers)
            ->and($emails[3]->label)->toBe(ContactLabel::General);
    });

    test('priority order', function () {
        $e = app(EmailExtractor::class);

        expect($e->priority('recruitment@x.sa'))->toBeLessThan($e->priority('jobs@x.sa'))
            ->and($e->priority('jobs@x.sa'))->toBeLessThan($e->priority('info@x.sa'))
            ->and($e->priority('info@x.sa'))->toBeLessThan($e->priority('contact@x.sa'))
            ->and($e->priority('contact@x.sa'))->toBeLessThan($e->priority('ahmed@x.sa'));
    });
});

describe('HtmlDocument', function () {
    test('reads meta, site name, absolute links and visible text without scripts', function () {
        $doc = fixture('contact-ar.html');
        $urls = collect($doc->links())->pluck('url');

        expect($doc->metaDescription())->toContain('تطوير المواقع')
            ->and($doc->siteName())->toBe('أكمي للبرمجيات')
            ->and($urls)->toContain('https://www.acme.com.sa/ar/about', 'https://www.acme.com.sa/careers', 'https://www.linkedin.com/company/acme-soft/')
            ->and($doc->visibleText())->toContain('تواصل معنا')
            ->and($doc->visibleText())->not->toContain('tracking');
    });
});
