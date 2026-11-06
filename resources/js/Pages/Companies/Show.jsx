import Icon, { WhatsAppIcon } from '@/Components/Icon';
import { ACTION_COLORS, Badge, Button, Panel, RelevanceBadge, STATUS_COLORS } from '@/Components/UI';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

function LinkRow({ href, icon, children }) {
    if (!href) return null;
    return (
        <a href={href} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 text-sm text-emerald-700 hover:underline">
            <Icon name={icon} className="h-4 w-4" />
            <span className="truncate">{children}</span>
        </a>
    );
}

export default function CompanyShow({ company, history }) {
    const post = (name) => router.post(route(name, company.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout
            header={company.name}
            actions={
                <>
                    <Button variant="secondary" size="sm" onClick={() => post('companies.enrich')}>
                        <Icon name="refresh" className="h-4 w-4" /> أعد جمع البيانات
                    </Button>
                    {company.status !== 'shown' && (
                        <Button variant="secondary" size="sm" onClick={() => post('companies.analyze')}>
                            <Icon name="sparkles" className="h-4 w-4" /> أعد التحليل
                        </Button>
                    )}
                </>
            }
        >
            <Head title={company.name} />

            <Link href={route('companies.index')} className="mb-4 inline-block text-sm text-gray-500 hover:text-gray-800">
                ← كل الشركات
            </Link>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Panel>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge color={STATUS_COLORS[company.status]}>{company.status_label}</Badge>
                            <RelevanceBadge value={company.relevance} />
                            {company.is_software_company && <Badge color="blue">شركة برمجيات</Badge>}
                            {company.is_hiring_devs && <Badge color="green">بتوظف مطورين</Badge>}
                            {company.city && <Badge>{company.city}</Badge>}
                            <span className="text-xs text-gray-400">المصدر: {company.source_label}</span>
                        </div>

                        {company.reason_ar && <p className="mt-3 text-sm font-medium text-gray-800">{company.reason_ar}</p>}
                        {company.description_ar ? (
                            <p className="mt-2 leading-relaxed text-gray-700">{company.description_ar}</p>
                        ) : (
                            <p className="mt-2 text-sm text-gray-400">لم يتم التحليل بعد.</p>
                        )}

                        {company.services?.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-1">
                                {company.services.map((s) => (
                                    <Badge key={s} color="violet">{s}</Badge>
                                ))}
                            </div>
                        )}
                        {company.tech_hints?.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1" dir="ltr">
                                {company.tech_hints.map((t) => (
                                    <Badge key={t}>{t}</Badge>
                                ))}
                            </div>
                        )}
                        {company.llm_driver && <p className="mt-3 text-xs text-gray-400">التحليل بواسطة: {company.llm_driver}</p>}
                    </Panel>

                    <Panel title="وسائل التواصل">
                        {company.phones.length + company.emails.length === 0 ? (
                            <p className="text-sm text-gray-400">لا توجد وسائل تواصل.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100 text-sm">
                                {[...company.phones, ...company.emails].map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center gap-2 py-2">
                                        {c.type === 'email' ? (
                                            <Icon name="mail" className="h-4 w-4 text-gray-400" />
                                        ) : c.can_whatsapp ? (
                                            <WhatsAppIcon className="h-4 w-4 text-[#25D366]" />
                                        ) : (
                                            <Icon name="phone" className="h-4 w-4 text-gray-400" />
                                        )}
                                        <span dir="ltr" className="font-medium text-gray-800">
                                            {c.display}
                                        </span>
                                        <Badge>{c.label_text}</Badge>
                                        {c.type === 'phone' && (
                                            <span className="text-xs text-gray-400">{c.is_whatsapp ? 'واتساب' : c.is_mobile ? 'جوال' : 'ثابت'}</span>
                                        )}
                                        {c.source_url && (
                                            <a href={c.source_url.startsWith('http') ? c.source_url : undefined} target="_blank" rel="noopener noreferrer" className="ms-auto truncate text-xs text-gray-400 hover:underline" dir="ltr">
                                                {c.source_url}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="mt-3 flex flex-wrap gap-2">
                            {company.whatsapp_url && (
                                <Button as="a" href={company.whatsapp_url} target="_blank" rel="noopener noreferrer" variant="whatsapp" size="sm">
                                    <WhatsAppIcon className="h-4 w-4" /> افتح واتساب
                                </Button>
                            )}
                            {company.mailto_url && (
                                <Button as="a" href={company.mailto_url} variant="secondary" size="sm">
                                    <Icon name="mail" className="h-4 w-4" /> إيميل
                                </Button>
                            )}
                        </div>
                    </Panel>

                    {(company.source_snippet || company.meta_description) && (
                        <Panel title="من نتائج البحث والموقع">
                            {company.source_snippet && (
                                <p className="whitespace-pre-line text-sm text-gray-600" dir="auto">{company.source_snippet}</p>
                            )}
                            {company.meta_description && (
                                <p className="mt-2 border-t border-gray-100 pt-2 text-sm text-gray-600" dir="auto">{company.meta_description}</p>
                            )}
                        </Panel>
                    )}

                    {company.pages.length > 0 && (
                        <Panel title="الصفحات التي تمت زيارتها">
                            <ul className="space-y-1 text-xs">
                                {company.pages.map((p, i) => (
                                    <li key={i} className="flex gap-2" dir="ltr">
                                        <span className={p.status.startsWith('ok') || p.status === 'rendered' ? 'text-emerald-700' : 'text-red-600'}>
                                            {p.status.startsWith('ok') || p.status === 'rendered' ? '✓' : '✗'}
                                        </span>
                                        <span className="truncate text-gray-700">{decodeURI(p.url)}</span>
                                        <span className="ms-auto shrink-0 text-gray-400">{p.status.slice(0, 60)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>

                <div className="space-y-4">
                    <Panel title="روابط">
                        <div className="space-y-2">
                            <LinkRow href={company.website} icon="globe">{company.domain ?? company.website}</LinkRow>
                            <LinkRow href={company.maps_url} icon="map">خرائط Google</LinkRow>
                            <LinkRow href={company.job_post_url} icon="briefcase">إعلان الوظيفة</LinkRow>
                            <LinkRow href={company.linkedin_url} icon="link">LinkedIn</LinkRow>
                        </div>
                    </Panel>

                    {company.batch_item && (
                        <Panel title="في الدفعات">
                            <div className="flex items-center justify-between text-sm">
                                <span>دفعة {company.batch_item.date}</span>
                                <Badge color={ACTION_COLORS[company.batch_item.action]}>{company.batch_item.action_label}</Badge>
                            </div>
                            {company.batch_item.note && <p className="mt-2 text-sm text-gray-600">{company.batch_item.note}</p>}
                        </Panel>
                    )}

                    <Panel title="السجل">
                        <ol className="space-y-3 border-s border-gray-200 ps-4">
                            {history.map((h, i) => (
                                <li key={i} className="relative">
                                    <span className="absolute -start-[1.3rem] top-1.5 h-2 w-2 rounded-full bg-emerald-600" />
                                    <div className="text-xs text-gray-400">{h.at}</div>
                                    <div className="text-sm text-gray-700">{h.text}</div>
                                </li>
                            ))}
                        </ol>
                    </Panel>

                    {company.enrichment_errors.length > 0 && (
                        <Panel title="ملاحظات الزحف">
                            <ul className="space-y-1 text-xs text-amber-800" dir="ltr">
                                {company.enrichment_errors.map((e, i) => (
                                    <li key={i}>{e}</li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
