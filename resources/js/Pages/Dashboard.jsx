import CompanyCard from '@/Components/CompanyCard';
import Icon from '@/Components/Icon';
import { Banners, Button, EmptyState, ProgressBar, Stat } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function RunButtons() {
    const [busy, setBusy] = useState(null);
    const post = (name) => {
        setBusy(name);
        router.post(route(name), {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <>
            <Button variant="secondary" size="sm" disabled={busy !== null} onClick={() => post('discover')}>
                <Icon name="search" className="h-4 w-4" />
                {busy === 'discover' ? 'جاري…' : 'شغّل البحث الآن'}
            </Button>
            <Button size="sm" disabled={busy !== null} onClick={() => post('batches.store')}>
                <Icon name="refresh" className="h-4 w-4" />
                {busy === 'batches.store' ? 'جاري…' : 'جهّز دفعة اليوم'}
            </Button>
        </>
    );
}

export default function Dashboard({ today, batch, progress, batchTime, stats, banners }) {
    return (
        <AuthenticatedLayout header="اليوم" actions={<RunButtons />}>
            <Head title="اليوم" />

            <div className="space-y-5">
                <p className="text-sm text-gray-500">
                    {today}
                    {batch?.generated_at && ` · الدفعة جُهّزت الساعة ${batch.generated_at}`}
                </p>

                <Banners banners={banners} />

                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border border-gray-200 bg-white p-4 sm:col-span-2">
                        <ProgressBar label="تم الإرسال اليوم" value={progress.sent} total={progress.total} />
                        <div className="mt-2 text-xs text-gray-400">
                            تم التعامل مع {progress.done} من {progress.total}
                        </div>
                    </div>
                    <Stat label="إجمالي المُرسَل" value={stats.sent} />
                    <Stat label="الردود" value={stats.replies} hint={`نسبة الرد ${stats.reply_rate}%`} />
                </div>

                {batch && batch.items.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {batch.items.map((item) => (
                            <CompanyCard key={item.id} item={item} />
                        ))}
                    </div>
                ) : (
                    <EmptyState title="لا توجد دفعة لليوم بعد">
                        تُجهَّز الدفعة تلقائياً الساعة <span dir="ltr">{batchTime}</span>، أو اضغط "جهّز دفعة اليوم".
                    </EmptyState>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
