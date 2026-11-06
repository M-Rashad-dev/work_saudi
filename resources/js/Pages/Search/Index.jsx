import Icon from '@/Components/Icon';
import { Badge, Button, Field, Panel, inputClass } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const STAT_LABELS = {
    new_companies: 'شركات جديدة',
    duplicates: 'مكررة',
    queries_added: 'جمل بحث جديدة',
    contacts_from_sources: 'وسائل تواصل من المصادر',
    analysis_backlog_queued: 'أُرسلت للتحليل',
    pool_size: 'المؤهلة المتاحة',
    added: 'أضيفت للدفعة',
    total: 'إجمالي الدفعة',
    whatsapp: 'بواتساب',
    email_only: 'إيميل فقط',
    pool_remaining: 'المتبقي',
};

function QueryRow({ q, groups, cities }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({ query: q.query, group: q.group, language: q.language, city: q.city ?? '' });

    const toggle = () => router.patch(route('search.queries.update', q.id), { is_active: !q.is_active }, { preserveScroll: true });
    const remove = () => confirm('حذف جملة البحث؟') && router.delete(route('search.queries.destroy', q.id), { preserveScroll: true });

    if (editing) {
        return (
            <li className="space-y-2 py-3">
                <input value={data.query} onChange={(e) => setData('query', e.target.value)} className={inputClass} dir="auto" />
                {errors.query && <p className="text-xs text-red-600">{errors.query}</p>}
                <div className="grid grid-cols-3 gap-2">
                    <select value={data.group} onChange={(e) => setData('group', e.target.value)} className={inputClass}>
                        {groups.map((g) => <option key={g.value} value={g.value}>{g.label}</option>)}
                    </select>
                    <select value={data.language} onChange={(e) => setData('language', e.target.value)} className={inputClass}>
                        <option value="ar">عربي</option>
                        <option value="en">English</option>
                    </select>
                    <select value={data.city} onChange={(e) => setData('city', e.target.value)} className={inputClass}>
                        <option value="">كل السعودية</option>
                        {cities.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                    </select>
                </div>
                <div className="flex gap-2">
                    <Button size="sm" disabled={processing} onClick={() => patch(route('search.queries.update', q.id), { preserveScroll: true, onSuccess: () => setEditing(false) })}>
                        حفظ
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>إلغاء</Button>
                </div>
            </li>
        );
    }

    return (
        <li className={`flex flex-wrap items-center gap-2 py-2.5 ${q.is_active ? '' : 'opacity-50'}`}>
            <button
                type="button"
                onClick={toggle}
                role="switch"
                aria-checked={q.is_active}
                title={q.is_active ? 'إيقاف' : 'تفعيل'}
                className={`relative h-5 w-9 shrink-0 rounded-full transition ${q.is_active ? 'bg-emerald-600' : 'bg-gray-300'}`}
            >
                <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white transition-all ${q.is_active ? 'start-[1.1rem]' : 'start-0.5'}`} />
            </button>
            <span className="min-w-0 flex-1 truncate font-medium text-gray-800" dir="auto">{q.query}</span>
            <Badge color={q.group === 'hiring' ? 'green' : 'blue'}>{q.group_label}</Badge>
            <Badge>{q.city_label}</Badge>
            <Badge>{q.language === 'ar' ? 'ع' : 'EN'}</Badge>
            <span className="w-28 text-xs text-gray-400">{q.last_run_at ? `${q.last_run_at} · ${q.results_count} نتيجة` : 'لم تُشغّل بعد'}</span>
            <button type="button" onClick={() => setEditing(true)} className="text-gray-400 hover:text-gray-700" title="تعديل">
                <Icon name="note" className="h-4 w-4" />
            </button>
            <button type="button" onClick={remove} className="text-gray-400 hover:text-red-600" title="حذف">
                <Icon name="trash" className="h-4 w-4" />
            </button>
        </li>
    );
}

function RunCard({ run }) {
    const sources = Object.entries(run.stats).filter(([, v]) => v && typeof v === 'object' && !Array.isArray(v));
    const totals = Object.entries(run.stats).filter(([k, v]) => STAT_LABELS[k] && typeof v !== 'object');

    return (
        <li className="py-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge color={run.type === 'build_batch' ? 'violet' : 'blue'}>{run.type_label}</Badge>
                <span className="text-sm text-gray-700">{run.started_at}</span>
                {run.running ? <Badge color="amber">قيد التشغيل…</Badge> : <span className="text-xs text-gray-400">{run.duration}</span>}
                {run.errors.length > 0 && <Badge color="red">{run.errors.length} خطأ</Badge>}
            </div>
            {totals.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                    {totals.map(([k, v]) => (
                        <span key={k}>{STAT_LABELS[k]}: <b>{String(v)}</b></span>
                    ))}
                </div>
            )}
            {sources.length > 0 && (
                <details className="mt-1 text-xs text-gray-500">
                    <summary className="cursor-pointer">تفاصيل المصادر</summary>
                    <div className="mt-1 space-y-1" dir="ltr">
                        {sources.map(([name, s]) => (
                            <div key={name}>
                                <b>{name}</b>: {Object.entries(s).map(([k, v]) => `${k}=${v}`).join(' · ')}
                            </div>
                        ))}
                    </div>
                </details>
            )}
            {run.errors.length > 0 && (
                <ul className="mt-2 space-y-0.5 text-xs text-red-700" dir="auto">
                    {run.errors.slice(0, 5).map((e, i) => <li key={i} className="break-all">• {e}</li>)}
                </ul>
            )}
        </li>
    );
}

export default function SearchIndex({ queries, runs, groups, cities, pool }) {
    const [filter, setFilter] = useState('all');
    const form = useForm({ query: '', group: 'discovery', language: 'ar', city: '' });

    const visible = useMemo(() => queries.filter((q) => filter === 'all' || q.group === filter), [queries, filter]);
    const active = queries.filter((q) => q.is_active).length;

    const add = (e) => {
        e.preventDefault();
        form.post(route('search.queries.store'), { preserveScroll: true, onSuccess: () => form.reset('query') });
    };

    return (
        <AuthenticatedLayout
            header="البحث"
            actions={
                <Button size="sm" variant="secondary" onClick={() => router.post(route('discover'), {}, { preserveScroll: true })}>
                    <Icon name="search" className="h-4 w-4" /> شغّل البحث الآن
                </Button>
            }
        >
            <Head title="البحث" />

            <div className="mb-4 flex flex-wrap gap-2 text-sm">
                <Badge color={pool.size >= pool.target ? 'green' : 'amber'}>المؤهلة المتاحة: {pool.size}/{pool.target}</Badge>
                <Badge>{active} جملة نشطة من {queries.length}</Badge>
            </div>

            <div className="grid gap-4 lg:grid-cols-5">
                <div className="space-y-4 lg:col-span-3">
                    <Panel title="إضافة جملة بحث">
                        <form onSubmit={add} className="grid gap-2 sm:grid-cols-6">
                            <Field label="الجملة" error={form.errors.query} className="sm:col-span-6">
                                <input value={form.data.query} onChange={(e) => form.setData('query', e.target.value)} placeholder="مثلاً: شركة برمجيات الرياض" className={inputClass} dir="auto" />
                            </Field>
                            <select value={form.data.group} onChange={(e) => form.setData('group', e.target.value)} className={`${inputClass} sm:col-span-2`}>
                                {groups.map((g) => <option key={g.value} value={g.value}>{g.label}</option>)}
                            </select>
                            <select value={form.data.language} onChange={(e) => form.setData('language', e.target.value)} className={`${inputClass} sm:col-span-1`}>
                                <option value="ar">عربي</option>
                                <option value="en">English</option>
                            </select>
                            <select value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} className={`${inputClass} sm:col-span-2`}>
                                <option value="">كل السعودية</option>
                                {cities.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                            </select>
                            <Button type="submit" disabled={form.processing} className="sm:col-span-1">إضافة</Button>
                        </form>
                    </Panel>

                    <Panel
                        title="جمل البحث"
                        actions={
                            <div className="flex gap-1">
                                {[['all', 'الكل'], ...groups.map((g) => [g.value, g.label])].map(([v, l]) => (
                                    <button key={v} type="button" onClick={() => setFilter(v)} className={`rounded-full px-2.5 py-1 text-xs ${filter === v ? 'bg-emerald-700 text-white' : 'bg-gray-100 text-gray-600'}`}>
                                        {l}
                                    </button>
                                ))}
                            </div>
                        }
                    >
                        <ul className="divide-y divide-gray-100 text-sm">
                            {visible.map((q) => <QueryRow key={q.id} q={q} groups={groups} cities={cities} />)}
                        </ul>
                    </Panel>
                </div>

                <Panel title="آخر عمليات التشغيل" className="lg:col-span-2">
                    {runs.length === 0 ? (
                        <p className="text-sm text-gray-400">لا توجد عمليات تشغيل بعد.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">{runs.map((r) => <RunCard key={r.id} run={r} />)}</ul>
                    )}
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}
