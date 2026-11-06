import Icon, { WhatsAppIcon } from '@/Components/Icon';
import { Badge, Button, EmptyState, Pagination, RelevanceBadge, STATUS_COLORS, inputClass } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function CompaniesIndex({ companies, filters, options, counts }) {
    const [form, setForm] = useState({
        q: filters.q ?? '',
        city: filters.city ?? '',
        status: filters.status ?? '',
        source: filters.source ?? '',
        min_relevance: filters.min_relevance ?? '',
        has_whatsapp: filters.has_whatsapp ?? '',
    });

    const apply = (next = form) => {
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== null));
        router.get(route('companies.index'), params, { preserveState: true, preserveScroll: true, replace: true });
    };
    const set = (key) => (e) => {
        const next = { ...form, [key]: e.target.value };
        setForm(next);
        if (key !== 'q' && key !== 'min_relevance') apply(next);
    };
    const reset = () => {
        const empty = { q: '', city: '', status: '', source: '', min_relevance: '', has_whatsapp: '' };
        setForm(empty);
        apply(empty);
    };

    return (
        <AuthenticatedLayout
            header="الشركات"
            actions={
                <Button as={Link} href={route('companies.create')} size="sm">
                    <Icon name="plus" className="h-4 w-4" /> إضافة شركة
                </Button>
            }
        >
            <Head title="الشركات" />

            {/* Status counters */}
            <div className="mb-4 flex flex-wrap gap-2">
                {options.statuses.map((s) => (
                    <button
                        key={s.value}
                        type="button"
                        onClick={() => set('status')({ target: { value: form.status === s.value ? '' : s.value } })}
                        className={`rounded-full border px-3 py-1 text-xs ${form.status === s.value ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-gray-200 bg-white text-gray-600'}`}
                    >
                        {s.label} <span className="opacity-70">({counts[s.value] ?? 0})</span>
                    </button>
                ))}
            </div>

            {/* Filters */}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    apply();
                }}
                className="mb-4 grid grid-cols-2 gap-2 rounded-xl border border-gray-200 bg-white p-3 md:grid-cols-6"
            >
                <input value={form.q} onChange={set('q')} placeholder="بحث بالاسم أو الدومين" className={`${inputClass} col-span-2`} />
                <select value={form.city} onChange={set('city')} className={inputClass}>
                    <option value="">كل المدن</option>
                    {options.cities.map((c) => (
                        <option key={c}>{c}</option>
                    ))}
                </select>
                <select value={form.source} onChange={set('source')} className={inputClass}>
                    <option value="">كل المصادر</option>
                    {options.sources.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
                <select value={form.has_whatsapp} onChange={set('has_whatsapp')} className={inputClass}>
                    <option value="">واتساب: الكل</option>
                    <option value="1">لديها واتساب</option>
                    <option value="0">بدون واتساب</option>
                </select>
                <input
                    type="number"
                    min="0"
                    max="100"
                    value={form.min_relevance}
                    onChange={set('min_relevance')}
                    onBlur={() => apply()}
                    placeholder="أقل ملاءمة"
                    className={inputClass}
                />
                <div className="col-span-2 flex gap-2 md:col-span-6">
                    <Button type="submit" size="sm">
                        تطبيق
                    </Button>
                    <Button size="sm" variant="ghost" onClick={reset}>
                        مسح الفلاتر
                    </Button>
                    <span className="ms-auto self-center text-xs text-gray-400">{companies.total} شركة</span>
                </div>
            </form>

            {companies.data.length === 0 ? (
                <EmptyState title="لا توجد شركات">جرّب تغيير الفلاتر أو شغّل البحث.</EmptyState>
            ) : (
                <>
                    {/* Desktop table */}
                    <div className="hidden overflow-hidden rounded-xl border border-gray-200 bg-white md:block">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-xs text-gray-500">
                                <tr>
                                    <th className="px-3 py-2 text-start font-medium">الشركة</th>
                                    <th className="px-3 py-2 text-start font-medium">المدينة</th>
                                    <th className="px-3 py-2 text-start font-medium">الحالة</th>
                                    <th className="px-3 py-2 text-start font-medium">الملاءمة</th>
                                    <th className="px-3 py-2 text-start font-medium">التواصل</th>
                                    <th className="px-3 py-2 text-start font-medium">المصدر</th>
                                    <th className="px-3 py-2 text-start font-medium">أضيفت</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {companies.data.map((c) => (
                                    <tr key={c.id} className="hover:bg-gray-50">
                                        <td className="px-3 py-2">
                                            <Link href={route('companies.show', c.id)} className="font-medium text-gray-900 hover:text-emerald-700">
                                                {c.name}
                                            </Link>
                                            {c.domain && (
                                                <div className="text-xs text-gray-400" dir="ltr">
                                                    {c.domain}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-gray-600">{c.city ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            <Badge color={STATUS_COLORS[c.status]}>{c.status_label}</Badge>
                                        </td>
                                        <td className="px-3 py-2">
                                            <RelevanceBadge value={c.relevance} />
                                            {c.is_hiring_devs && <Badge color="green" className="ms-1">بتوظف</Badge>}
                                        </td>
                                        <td className="px-3 py-2 text-gray-600">
                                            <span className="inline-flex items-center gap-1">
                                                {c.has_whatsapp && <WhatsAppIcon className="h-4 w-4 text-[#25D366]" />}
                                                {c.contacts_count}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-gray-500">{c.source_label}</td>
                                        <td className="px-3 py-2 text-xs text-gray-400">{c.created_at}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile list */}
                    <ul className="space-y-2 md:hidden">
                        {companies.data.map((c) => (
                            <li key={c.id}>
                                <Link href={route('companies.show', c.id)} className="block rounded-xl border border-gray-200 bg-white p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="truncate font-semibold text-gray-900">{c.name}</span>
                                        <Badge color={STATUS_COLORS[c.status]}>{c.status_label}</Badge>
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                                        {c.city && <span>{c.city}</span>}
                                        <RelevanceBadge value={c.relevance} />
                                        {c.has_whatsapp && <WhatsAppIcon className="h-4 w-4 text-[#25D366]" />}
                                        <span>{c.contacts_count} وسيلة تواصل</span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <Pagination links={companies.links} />
        </AuthenticatedLayout>
    );
}
