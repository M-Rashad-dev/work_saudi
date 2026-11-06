import Icon from '@/Components/Icon';
import { Badge, Button, Field, Panel, inputClass } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const SAMPLE_COMPANY = 'شركة مثال للبرمجيات';

function fill(template, data) {
    return (template ?? '')
        .replaceAll('{company_name}', SAMPLE_COMPANY)
        .replaceAll('{my_name}', data.my_name || '{my_name}')
        .replaceAll('{cv_url}', data.cv_url || '{cv_url}')
        .replaceAll('{portfolio_url}', data.portfolio_url || '{portfolio_url}');
}

function DriverOrder({ value, drivers, onChange, error }) {
    const byName = Object.fromEntries(drivers.map((d) => [d.name, d]));
    const enabled = value.filter((n) => byName[n]);
    const disabled = drivers.filter((d) => !enabled.includes(d.name));

    const move = (i, dir) => {
        const next = [...enabled];
        [next[i], next[i + dir]] = [next[i + dir], next[i]];
        onChange(next);
    };

    return (
        <div className="space-y-2">
            <ol className="space-y-1.5">
                {enabled.map((name, i) => (
                    <li key={name} className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                        <span className="font-bold text-gray-400">{i + 1}</span>
                        <span className="flex-1">{byName[name].label}</span>
                        {!byName[name].available && <Badge color="amber">غير مُعدّ</Badge>}
                        <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="text-gray-400 hover:text-gray-800 disabled:opacity-30" title="لأعلى">
                            <Icon name="up" className="h-4 w-4" />
                        </button>
                        <button type="button" disabled={i === enabled.length - 1} onClick={() => move(i, 1)} className="text-gray-400 hover:text-gray-800 disabled:opacity-30" title="لأسفل">
                            <Icon name="down" className="h-4 w-4" />
                        </button>
                        <button type="button" disabled={enabled.length === 1} onClick={() => onChange(enabled.filter((n) => n !== name))} className="text-gray-400 hover:text-red-600 disabled:opacity-30" title="إزالة">
                            <Icon name="trash" className="h-4 w-4" />
                        </button>
                    </li>
                ))}
            </ol>
            {disabled.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {disabled.map((d) => (
                        <Button key={d.name} size="sm" variant="secondary" onClick={() => onChange([...enabled, d.name])}>
                            <Icon name="plus" className="h-3.5 w-3.5" /> {d.label}
                        </Button>
                    ))}
                </div>
            )}
            <p className="text-xs text-gray-400">يُجرّب الأول، ولو فشل ينتقل للي بعده. "غير مُعدّ" = لا يوجد مفتاح/رابط في ملف .env فيتم تخطيه.</p>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}

function BlockedDomains({ domains }) {
    const [search, setSearch] = useState('');
    const form = useForm({ domain: '' });
    const shown = useMemo(() => domains.filter((d) => d.domain.includes(search.trim().toLowerCase())), [domains, search]);

    return (
        <Panel title={`النطاقات المحظورة (${domains.length})`}>
            <p className="mb-3 text-xs text-gray-500">
                لا تُعتبر نتائج هذه المواقع شركات (أدلة، شبكات اجتماعية، أخبار، جهات حكومية…). الحظر يشمل النطاقات الفرعية: <span dir="ltr">gov.sa</span> يحظر كل <span dir="ltr">*.gov.sa</span>. مواقع التوظيف يُستخرج منها اسم الشركة.
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('settings.blocked-domains.store'), { preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="mb-3 flex gap-2"
            >
                <input value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} placeholder="example.com" dir="ltr" className={inputClass} />
                <Button type="submit" size="sm" disabled={form.processing}>حظر</Button>
            </form>
            {form.errors.domain && <p className="-mt-2 mb-2 text-xs text-red-600">{form.errors.domain}</p>}
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="بحث…" dir="ltr" className={`${inputClass} mb-2`} />
            <ul className="max-h-72 divide-y divide-gray-100 overflow-y-auto text-sm">
                {shown.map((d) => (
                    <li key={d.id} className="flex items-center gap-2 py-1.5" dir="ltr">
                        <span className="flex-1 text-gray-800">{d.domain}</span>
                        {d.category && <Badge color={d.category === 'job_board' ? 'green' : 'gray'}>{d.category}</Badge>}
                        <button
                            type="button"
                            onClick={() => router.delete(route('settings.blocked-domains.destroy', d.id), { preserveScroll: true })}
                            className="text-gray-400 hover:text-red-600"
                            title="حذف"
                        >
                            <Icon name="trash" className="h-4 w-4" />
                        </button>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

export default function SettingsEdit({ settings, placeholders, drivers, blockedDomains, sources }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        my_name: settings.my_name ?? '',
        cv_url: settings.cv_url ?? '',
        portfolio_url: settings.portfolio_url ?? '',
        message_template: settings.message_template ?? '',
        email_subject: settings.email_subject ?? '',
        email_body: settings.email_body ?? '',
        batch_size: settings.batch_size,
        batch_time: settings.batch_time,
        min_relevance: settings.min_relevance,
        llm_drivers: settings.llm_drivers ?? [],
    });

    const bind = (key) => ({ value: data[key], onChange: (e) => setData(key, e.target.value) });
    const submit = (e) => {
        e.preventDefault();
        put(route('settings.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="الإعدادات">
            <Head title="الإعدادات" />

            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="بياناتي">
                        <div className="space-y-3">
                            <Field label="اسمي" error={errors.my_name}>
                                <input {...bind('my_name')} className={inputClass} />
                            </Field>
                            <Field label="رابط السيرة الذاتية" error={errors.cv_url}>
                                <input {...bind('cv_url')} dir="ltr" placeholder="https://…" className={inputClass} />
                            </Field>
                            <Field label="رابط الأعمال" error={errors.portfolio_url}>
                                <input {...bind('portfolio_url')} dir="ltr" placeholder="https://…" className={inputClass} />
                            </Field>
                        </div>
                    </Panel>

                    <Panel title="الدفعة اليومية">
                        <div className="grid grid-cols-3 gap-3">
                            <Field label="عدد الشركات" error={errors.batch_size}>
                                <input {...bind('batch_size')} type="number" min="1" max="50" className={inputClass} />
                            </Field>
                            <Field label="وقت التجهيز" error={errors.batch_time} hint="بتوقيت القاهرة">
                                <input {...bind('batch_time')} type="time" className={inputClass} />
                            </Field>
                            <Field label="أقل ملاءمة" error={errors.min_relevance} hint="0–100">
                                <input {...bind('min_relevance')} type="number" min="0" max="100" className={inputClass} />
                            </Field>
                        </div>
                        <div className="mt-4">
                            <span className="mb-1 block text-sm font-medium text-gray-700">ترتيب محركات الذكاء الاصطناعي</span>
                            <DriverOrder value={data.llm_drivers} drivers={drivers} onChange={(v) => setData('llm_drivers', v)} error={errors.llm_drivers} />
                        </div>
                    </Panel>
                </div>

                <Panel title="رسالة واتساب">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Field label="نص الرسالة" error={errors.message_template} hint={`المتغيرات: ${placeholders.join('  ')}`}>
                            <textarea {...bind('message_template')} rows={9} className={inputClass} />
                        </Field>
                        <div>
                            <span className="mb-1 block text-sm font-medium text-gray-700">معاينة</span>
                            <div className="whitespace-pre-wrap rounded-2xl rounded-ss-sm bg-[#dcf8c6] p-3 text-sm leading-relaxed text-gray-900 shadow-sm">
                                {fill(data.message_template, data)}
                            </div>
                        </div>
                    </div>
                </Panel>

                <Panel title="الإيميل">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <div className="space-y-3">
                            <Field label="عنوان الإيميل" error={errors.email_subject}>
                                <input {...bind('email_subject')} className={inputClass} />
                            </Field>
                            <Field label="نص الإيميل" error={errors.email_body}>
                                <textarea {...bind('email_body')} rows={7} className={inputClass} />
                            </Field>
                            <Button size="sm" variant="ghost" onClick={() => setData('email_body', data.message_template)}>
                                انسخ نص رسالة واتساب
                            </Button>
                        </div>
                        <div>
                            <span className="mb-1 block text-sm font-medium text-gray-700">معاينة</span>
                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm">
                                <div className="border-b border-gray-200 pb-2 font-semibold">{fill(data.email_subject, data)}</div>
                                <div className="whitespace-pre-wrap pt-2 leading-relaxed text-gray-700">{fill(data.email_body, data)}</div>
                            </div>
                        </div>
                    </div>
                </Panel>

                <div className="sticky bottom-20 z-10 flex justify-end md:bottom-4">
                    <Button type="submit" disabled={processing || !isDirty} className="shadow-lg">
                        حفظ الإعدادات
                    </Button>
                </div>
            </form>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                <BlockedDomains domains={blockedDomains} />

                <Panel title="مصادر البحث">
                    <ul className="space-y-3 text-sm">
                        <li className="flex items-center justify-between">
                            <span>SearXNG (ذاتي الاستضافة)</span>
                            <Badge color="green">أساسي</Badge>
                        </li>
                        <li className="flex items-center justify-between">
                            <span>OpenStreetMap Overpass</span>
                            <Badge color="green">مجاني</Badge>
                        </li>
                        <li className="flex flex-wrap items-center justify-between gap-2">
                            <span>Serper (اختياري)</span>
                            {!sources.serper.configured ? (
                                <Badge>غير مُعدّ — أضف SERPER_API_KEY</Badge>
                            ) : sources.serper.disabled ? (
                                <span className="flex items-center gap-2">
                                    <Badge color="red">متوقف تلقائياً</Badge>
                                    <Button size="sm" variant="secondary" onClick={() => router.delete(route('settings.sources.enable', 'serper'), { preserveScroll: true })}>
                                        تفعيل
                                    </Button>
                                </span>
                            ) : (
                                <Badge color="green">مفعّل</Badge>
                            )}
                        </li>
                        {sources.serper.disabled && <li className="text-xs text-gray-500" dir="auto">{sources.serper.disabled.reason}</li>}
                    </ul>
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}
