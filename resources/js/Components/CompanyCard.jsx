import Icon, { WhatsAppIcon } from '@/Components/Icon';
import { ACTION_COLORS, Badge, Button, RelevanceBadge, inputClass } from '@/Components/UI';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

const ACTIONS = [
    { value: 'sent', label: 'تم الإرسال', active: 'bg-sky-600 text-white border-sky-600' },
    { value: 'skipped', label: 'تخطي', active: 'bg-amber-500 text-white border-amber-500' },
    { value: 'not_relevant', label: 'غير مناسبة', active: 'bg-red-600 text-white border-red-600' },
    { value: 'replied', label: 'ردّوا', active: 'bg-emerald-600 text-white border-emerald-600' },
];

function ExternalLink({ href, icon, children }) {
    if (!href) return null;
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 hover:text-gray-900"
        >
            <Icon name={icon} className="h-4 w-4" />
            {children}
        </a>
    );
}

/**
 * One company in a batch. The WhatsApp/email buttons only OPEN a prefilled message —
 * nothing is sent automatically — then ask "تم الإرسال؟".
 */
export default function CompanyCard({ item, compact = false }) {
    const { company } = item;
    const [askSent, setAskSent] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);
    const [note, setNote] = useState(item.note ?? '');
    const [busy, setBusy] = useState(false);

    const update = (data, after) => {
        setBusy(true);
        router.patch(route('batch-items.update', item.id), data, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
            onSuccess: after,
        });
    };

    const setAction = (value) => update({ action: item.action === value ? 'pending' : value }, () => setAskSent(false));

    const done = item.action !== 'pending';

    return (
        <article
            className={`flex flex-col rounded-xl border bg-white transition ${done ? 'border-gray-200 opacity-80' : 'border-gray-200 shadow-sm'}`}
        >
            {/* Header */}
            <div className="flex items-start justify-between gap-2 px-4 pt-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-600">
                            {item.position}
                        </span>
                        <Link href={route('companies.show', company.id)} className="truncate text-lg font-bold text-gray-900 hover:text-emerald-700">
                            {company.name}
                        </Link>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-gray-500">
                        {company.city && (
                            <span className="inline-flex items-center gap-1">
                                <Icon name="map" className="h-4 w-4" /> {company.city}
                            </span>
                        )}
                        <RelevanceBadge value={company.relevance} />
                        {company.email_only && <Badge color="amber">إيميل فقط</Badge>}
                    </div>
                </div>
                {done && <Badge color={ACTION_COLORS[item.action]}>{item.action_label}</Badge>}
            </div>

            <div className="flex-1 space-y-3 px-4 py-3">
                {/* Why it was picked */}
                {(company.reason_ar || company.job_post_url) && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        {company.reason_ar && (
                            <Badge color={company.is_hiring_devs ? 'green' : 'blue'}>
                                <Icon name={company.is_hiring_devs ? 'briefcase' : 'sparkles'} className="h-3.5 w-3.5" />
                                {company.reason_ar}
                            </Badge>
                        )}
                        {company.job_post_url && (
                            <a href={company.job_post_url} target="_blank" rel="noopener noreferrer" className="text-xs font-medium text-emerald-700 underline">
                                إعلان الوظيفة
                            </a>
                        )}
                    </div>
                )}

                {company.description_ar && (
                    <p className={`text-sm leading-relaxed text-gray-700 ${compact ? 'line-clamp-2' : 'line-clamp-3'}`}>{company.description_ar}</p>
                )}

                {!compact && company.tech_hints?.length > 0 && (
                    <div className="flex flex-wrap gap-1" dir="ltr">
                        {company.tech_hints.map((t) => (
                            <Badge key={t}>{t}</Badge>
                        ))}
                    </div>
                )}

                {/* Contacts */}
                {(company.phones.length > 0 || company.emails.length > 0) && (
                    <ul className="space-y-1 text-sm">
                        {company.phones.map((p) => (
                            <li key={p.id} className="flex items-center gap-2">
                                {p.can_whatsapp ? (
                                    <WhatsAppIcon className="h-4 w-4 text-[#25D366]" />
                                ) : (
                                    <Icon name="phone" className="h-4 w-4 text-gray-400" />
                                )}
                                <a href={`tel:${p.value}`} dir="ltr" className="font-medium text-gray-800 hover:underline">
                                    {p.display}
                                </a>
                                <span className="text-xs text-gray-400">
                                    {p.is_whatsapp ? 'واتساب' : p.is_mobile ? 'جوال' : 'هاتف ثابت'}
                                    {['hr', 'careers'].includes(p.label) && ` · ${p.label_text}`}
                                </span>
                            </li>
                        ))}
                        {company.emails.map((e) => (
                            <li key={e.id} className="flex items-center gap-2">
                                <Icon name="mail" className="h-4 w-4 text-gray-400" />
                                <span dir="ltr" className="truncate text-gray-800">
                                    {e.display}
                                </span>
                                {e.label !== 'general' && <span className="text-xs text-gray-400">{e.label_text}</span>}
                            </li>
                        ))}
                    </ul>
                )}

                {/* Links */}
                <div className="-mx-2 flex flex-wrap">
                    <ExternalLink href={company.website} icon="globe">الموقع</ExternalLink>
                    <ExternalLink href={company.maps_url} icon="map">خرائط Google</ExternalLink>
                    <ExternalLink href={company.job_post_url} icon="briefcase">الوظيفة</ExternalLink>
                    <ExternalLink href={company.linkedin_url} icon="link">LinkedIn</ExternalLink>
                </div>
            </div>

            {/* Send buttons */}
            <div className="space-y-2 border-t border-gray-100 px-4 py-3">
                <div className="flex flex-wrap gap-2">
                    {company.whatsapp_url && (
                        <Button
                            as="a"
                            href={company.whatsapp_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            variant="whatsapp"
                            className="flex-1"
                            onClick={() => setAskSent(true)}
                        >
                            <WhatsAppIcon className="h-5 w-5" />
                            واتساب <span dir="ltr" className="font-normal opacity-90">{company.whatsapp_display}</span>
                        </Button>
                    )}
                    {company.mailto_url && (
                        <Button
                            as="a"
                            href={company.mailto_url}
                            variant="secondary"
                            className={company.whatsapp_url ? '' : 'flex-1'}
                            onClick={() => setAskSent(true)}
                        >
                            <Icon name="mail" /> إيميل
                        </Button>
                    )}
                </div>

                {askSent && item.action !== 'sent' && (
                    <div className="flex items-center justify-between gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">
                        <span className="font-medium">تم الإرسال؟</span>
                        <div className="flex gap-2">
                            <Button size="sm" onClick={() => setAction('sent')} disabled={busy}>
                                نعم
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => setAskSent(false)}>
                                لا
                            </Button>
                        </div>
                    </div>
                )}

                {/* Status buttons */}
                <div className="grid grid-cols-4 gap-1.5">
                    {ACTIONS.map((a) => (
                        <button
                            key={a.value}
                            type="button"
                            disabled={busy}
                            onClick={() => setAction(a.value)}
                            title={item.action === a.value ? 'اضغط مرة أخرى للتراجع' : ''}
                            className={`rounded-lg border px-1 py-2 text-xs font-semibold transition disabled:opacity-50 ${
                                item.action === a.value ? a.active : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                            }`}
                        >
                            {a.label}
                        </button>
                    ))}
                </div>

                {/* Note */}
                {noteOpen ? (
                    <div className="space-y-2">
                        <textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            rows={2}
                            maxLength={1000}
                            className={inputClass}
                            placeholder="ملاحظة (مثلاً: ردّوا وطلبوا CV بالإنجليزي)"
                        />
                        <div className="flex gap-2">
                            <Button size="sm" disabled={busy} onClick={() => update({ note }, () => setNoteOpen(false))}>
                                حفظ
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setNoteOpen(false)}>
                                إلغاء
                            </Button>
                        </div>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => setNoteOpen(true)}
                        className="flex w-full items-center gap-1.5 text-start text-xs text-gray-500 hover:text-gray-800"
                    >
                        <Icon name="note" className="h-4 w-4" />
                        {item.note ? <span className="line-clamp-1">{item.note}</span> : 'إضافة ملاحظة'}
                    </button>
                )}
            </div>
        </article>
    );
}
