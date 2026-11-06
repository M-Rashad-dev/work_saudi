import { Button, Field, Panel, inputClass } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function CompanyCreate({ cities }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        website: '',
        city: '',
        phone: '',
        whatsapp: '',
        email: '',
        job_post_url: '',
        note: '',
    });

    const bind = (key) => ({ value: data[key], onChange: (e) => setData(key, e.target.value) });

    return (
        <AuthenticatedLayout header="إضافة شركة">
            <Head title="إضافة شركة" />

            <Link href={route('companies.index')} className="mb-4 inline-block text-sm text-gray-500 hover:text-gray-800">
                ← كل الشركات
            </Link>

            <Panel className="max-w-2xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(route('companies.store'));
                    }}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    <Field label="اسم الشركة *" error={errors.name} className="sm:col-span-2">
                        <input {...bind('name')} required className={inputClass} />
                    </Field>
                    <Field label="الموقع" error={errors.website} hint="سيتم جمع الأرقام والإيميلات منه تلقائياً">
                        <input {...bind('website')} dir="ltr" placeholder="example.com" className={inputClass} />
                    </Field>
                    <Field label="المدينة" error={errors.city}>
                        <select {...bind('city')} className={inputClass}>
                            <option value="">—</option>
                            {cities.map((c) => (
                                <option key={c}>{c}</option>
                            ))}
                        </select>
                    </Field>
                    <Field label="رقم واتساب" error={errors.whatsapp}>
                        <input {...bind('whatsapp')} dir="ltr" placeholder="05xxxxxxxx" className={inputClass} />
                    </Field>
                    <Field label="رقم هاتف" error={errors.phone}>
                        <input {...bind('phone')} dir="ltr" className={inputClass} />
                    </Field>
                    <Field label="الإيميل" error={errors.email}>
                        <input {...bind('email')} type="email" dir="ltr" className={inputClass} />
                    </Field>
                    <Field label="رابط إعلان وظيفة" error={errors.job_post_url}>
                        <input {...bind('job_post_url')} dir="ltr" className={inputClass} />
                    </Field>
                    <Field label="ملاحظة" error={errors.note} className="sm:col-span-2" hint="تُستخدم كمدخل إضافي للتحليل">
                        <textarea {...bind('note')} rows={3} className={inputClass} />
                    </Field>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            إضافة
                        </Button>
                    </div>
                </form>
            </Panel>
        </AuthenticatedLayout>
    );
}
