import CompanyCard from '@/Components/CompanyCard';
import { EmptyState, Pagination } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function ArchiveIndex({ batches, filters, actions }) {
    const chip = (value, label, count) => {
        const active = (filters.action ?? null) === value;
        return (
            <Link
                key={value ?? 'all'}
                href={route('archive.index', value ? { action: value } : {})}
                preserveScroll
                className={`rounded-full border px-3 py-1.5 text-sm ${active ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'}`}
            >
                {label}
                {count !== undefined && <span className="ms-1 opacity-70">({count})</span>}
            </Link>
        );
    };

    return (
        <AuthenticatedLayout header="الأرشيف">
            <Head title="الأرشيف" />

            <div className="mb-5 flex flex-wrap gap-2">
                {chip(null, 'الكل')}
                {actions.map((a) => chip(a.value, a.label, a.count))}
            </div>

            {batches.data.length === 0 ? (
                <EmptyState title="لا توجد دفعات">{filters.action ? 'لا توجد شركات بهذا الإجراء.' : 'ستظهر الدفعات السابقة هنا.'}</EmptyState>
            ) : (
                <div className="space-y-8">
                    {batches.data.map((batch) => (
                        <section key={batch.id}>
                            <h2 className="mb-3 flex items-baseline gap-2 font-bold text-gray-900">
                                {batch.date_label}
                                <span className="text-sm font-normal text-gray-400">
                                    {filters.action ? `${batch.items.length} من ${batch.items_count}` : `${batch.items_count} شركة`}
                                </span>
                            </h2>
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {batch.items.map((item) => (
                                    <CompanyCard key={item.id} item={item} compact />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <Pagination links={batches.links} />
        </AuthenticatedLayout>
    );
}
