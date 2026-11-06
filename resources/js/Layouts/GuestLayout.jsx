export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4">
            <div className="mb-6 flex flex-col items-center gap-3">
                <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-700 text-2xl font-bold text-white">
                    ش
                </span>
                <h1 className="text-lg font-bold text-gray-800">شركات اليوم</h1>
            </div>

            <div className="w-full max-w-sm rounded-2xl border border-gray-200 bg-white px-6 py-6 shadow-sm">
                {children}
            </div>
        </div>
    );
}
