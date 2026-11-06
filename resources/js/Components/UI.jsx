// Small shared building blocks for the dashboard pages.
import Icon from '@/Components/Icon';
import { Link } from '@inertiajs/react';

export function Panel({ title, actions, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-gray-200 bg-white ${className}`}>
            {(title || actions) && (
                <header className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
                    {title && <h2 className="font-semibold text-gray-900">{title}</h2>}
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </header>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

const BADGE_COLORS = {
    gray: 'bg-gray-100 text-gray-700',
    green: 'bg-emerald-100 text-emerald-800',
    blue: 'bg-sky-100 text-sky-800',
    amber: 'bg-amber-100 text-amber-800',
    red: 'bg-red-100 text-red-700',
    violet: 'bg-violet-100 text-violet-800',
};

export function Badge({ color = 'gray', children, className = '' }) {
    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ${BADGE_COLORS[color]} ${className}`}>
            {children}
        </span>
    );
}

export const STATUS_COLORS = { new: 'gray', enriched: 'blue', qualified: 'green', rejected: 'red', shown: 'violet' };

export const ACTION_COLORS = { pending: 'gray', sent: 'blue', skipped: 'amber', not_relevant: 'red', replied: 'green' };

export function RelevanceBadge({ value }) {
    const color = value >= 75 ? 'green' : value >= 50 ? 'blue' : value >= 30 ? 'amber' : 'gray';
    return <Badge color={color}>ملاءمة {value}</Badge>;
}

/** Button-styled element; pass `as={Link}` / href, or onClick. */
export function Button({ as: As = 'button', variant = 'primary', size = 'md', className = '', children, ...props }) {
    const variants = {
        primary: 'bg-emerald-700 text-white hover:bg-emerald-600 disabled:bg-emerald-300',
        secondary: 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-50',
        whatsapp: 'bg-[#25D366] text-white hover:bg-[#1fb857]',
        danger: 'border border-red-200 bg-white text-red-700 hover:bg-red-50',
        ghost: 'text-gray-600 hover:bg-gray-100',
    };
    const sizes = { sm: 'px-2.5 py-1.5 text-xs', md: 'px-3.5 py-2 text-sm', lg: 'px-4 py-3 text-base' };
    const type = As === 'button' ? { type: props.type ?? 'button' } : {};

    return (
        <As
            {...type}
            {...props}
            className={`inline-flex items-center justify-center gap-1.5 rounded-lg font-semibold transition focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1 ${variants[variant]} ${sizes[size]} ${className}`}
        >
            {children}
        </As>
    );
}

export function Banners({ banners }) {
    if (!banners?.length) return null;
    const styles = {
        error: 'border-red-200 bg-red-50 text-red-800',
        warning: 'border-amber-200 bg-amber-50 text-amber-900',
        info: 'border-sky-200 bg-sky-50 text-sky-900',
    };

    return (
        <div className="space-y-2">
            {banners.map((b, i) => (
                <div key={i} className={`flex gap-2 rounded-lg border px-4 py-3 text-sm ${styles[b.level]}`}>
                    <Icon name={b.level === 'info' ? 'info' : 'alert'} className="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <div className="font-medium">{b.message}</div>
                        {b.detail && <pre className="mt-1 whitespace-pre-wrap font-sans text-xs opacity-80" dir="auto">{b.detail}</pre>}
                    </div>
                </div>
            ))}
        </div>
    );
}

export function ProgressBar({ value, total, label }) {
    const pct = total > 0 ? Math.min(100, Math.round((value / total) * 100)) : 0;
    return (
        <div>
            <div className="mb-1 flex justify-between text-sm">
                <span className="font-medium text-gray-700">{label}</span>
                <span className="text-gray-500">
                    {value}/{total}
                </span>
            </div>
            <div className="h-2.5 overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full bg-emerald-600 transition-all" style={{ width: `${pct}%` }} />
            </div>
        </div>
    );
}

export function Stat({ label, value, hint }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="text-xs text-gray-500">{label}</div>
            <div className="mt-1 text-2xl font-bold text-gray-900">{value}</div>
            {hint && <div className="mt-0.5 text-xs text-gray-400">{hint}</div>}
        </div>
    );
}

export function EmptyState({ title, children }) {
    return (
        <div className="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center">
            <div className="font-semibold text-gray-700">{title}</div>
            {children && <div className="mt-2 text-sm text-gray-500">{children}</div>}
        </div>
    );
}

/** Laravel paginator links (labels come from lang/ar/pagination.php). */
export function Pagination({ links }) {
    if (!links || links.length <= 3) return null;
    return (
        <nav className="mt-4 flex flex-wrap justify-center gap-1">
            {links.map((link, i) =>
                link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll
                        className={`rounded-lg px-3 py-1.5 text-sm ${link.active ? 'bg-emerald-700 text-white' : 'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50'}`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span key={i} className="rounded-lg px-3 py-1.5 text-sm text-gray-300" dangerouslySetInnerHTML={{ __html: link.label }} />
                ),
            )}
        </nav>
    );
}

export function Field({ label, error, hint, children, className = '' }) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-sm font-medium text-gray-700">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-gray-400">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
        </label>
    );
}

export const inputClass =
    'block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500';
