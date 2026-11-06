import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit() {
    return (
        <AuthenticatedLayout header="الحساب">
            <Head title="الحساب" />

            <div className="space-y-6">
                <div className="rounded-xl border border-gray-200 bg-white p-4 sm:p-8">
                    <UpdateProfileInformationForm className="max-w-xl" />
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 sm:p-8">
                    <UpdatePasswordForm className="max-w-xl" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
