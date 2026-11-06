import Dropdown from '@/Components/Dropdown';
import Icon from '@/Components/Icon';
import { Link, usePage } from '@inertiajs/react';

export const NAV = [
    { route: 'dashboard', match: 'dashboard', label: 'اليوم', icon: 'today' },
    { route: 'archive.index', match: 'archive.*', label: 'الأرشيف', icon: 'archive' },
    { route: 'companies.index', match: 'companies.*', label: 'الشركات', icon: 'companies' },
    { route: 'search.index', match: 'search.*', label: 'البحث', icon: 'search' },
    { route: 'settings.index', match: 'settings.*', label: 'الإعدادات', icon: 'settings' },
];

function Flash() {
    const { flash } = usePage().props;
    if (!flash?.success && !flash?.error) return null;

    return (
        <div className="mx-auto max-w-6xl px-4 pt-4">
            {flash.success && (
                <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <Icon name="check" /> {flash.success}
                </div>
            )}
            {flash.error && (
                <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <Icon name="alert" /> {flash.error}
                </div>
            )}
        </div>
    );
}

export default function AuthenticatedLayout({ header, actions, children }) {
    const user = usePage().props.auth.user;

    return (
        <div className="min-h-screen bg-gray-50 pb-20 md:pb-0">
            {/* Top bar */}
            <header className="sticky top-0 z-30 border-b border-gray-200 bg-white/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4">
                    <Link href={route('dashboard')} className="flex items-center gap-2 font-bold text-emerald-700">
                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-700 text-sm text-white">
                            ش
                        </span>
                        <span className="hidden sm:inline">شركات اليوم</span>
                    </Link>

                    <nav className="hidden flex-1 items-center gap-1 md:flex">
                        {NAV.map((item) => {
                            const active = route().current(item.match);
                            return (
                                <Link
                                    key={item.route}
                                    href={route(item.route)}
                                    className={
                                        'flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition ' +
                                        (active
                                            ? 'bg-emerald-50 text-emerald-800'
                                            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900')
                                    }
                                >
                                    <Icon name={item.icon} className="h-4 w-4" />
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <Dropdown>
                        <Dropdown.Trigger>
                            <button
                                type="button"
                                className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100"
                            >
                                <Icon name="user" />
                                <span className="max-w-[10rem] truncate">{user.name}</span>
                            </button>
                        </Dropdown.Trigger>
                        <Dropdown.Content>
                            <Dropdown.Link href={route('profile.edit')}>الحساب</Dropdown.Link>
                            <Dropdown.Link href={route('logout')} method="post" as="button">
                                تسجيل الخروج
                            </Dropdown.Link>
                        </Dropdown.Content>
                    </Dropdown>
                </div>
            </header>

            <Flash />

            {(header || actions) && (
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 pt-6">
                    {header && <h1 className="text-xl font-bold text-gray-900">{header}</h1>}
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </div>
            )}

            <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>

            {/* Mobile bottom tabs */}
            <nav
                className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-gray-200 bg-white md:hidden"
                style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            >
                {NAV.map((item) => {
                    const active = route().current(item.match);
                    return (
                        <Link
                            key={item.route}
                            href={route(item.route)}
                            className={
                                'flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium ' +
                                (active ? 'text-emerald-700' : 'text-gray-500')
                            }
                        >
                            <Icon name={item.icon} className="h-6 w-6" />
                            {item.label}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
