import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { userThemeStyle } from '@/lib/user-theme';
import type { SharedProps } from '@/types/global';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { LanguageSwitcher } from '@/components/user/LanguageSwitcher';
import { AuthBrand } from '@/components/user/AuthBrand';

export default function Login() {
    useClientTranslation();
    useLocaleSync();
    const form = useForm({ identifier: '', password: '' });
    const { tenant, flash } = usePage<SharedProps>().props;
    const [visible, setVisible] = useState(false);
    return (
        <div
            className="user-theme min-h-screen bg-background text-foreground"
            style={userThemeStyle(tenant?.branding.primaryColor)}
        >
            <Head title={t('Sign in')} />
            <main className="user-auth-shell user-auth-designed">
                <div className="user-auth-banner user-auth-banner-promotional">
                    <div className="flex w-full items-center justify-between">
                        <Link
                            href="/"
                            aria-label={t('Back to home')}
                            className="grid size-11 place-items-center"
                        >
                            <ArrowLeft className="size-7" />
                        </Link>
                        <LanguageSwitcher />
                    </div>
                    <AuthBrand tenant={tenant} promotional />
                </div>
                <div className="user-auth-content">
                    {flash.success && (
                        <p
                            role="status"
                            className="mb-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-800"
                        >
                            {t(flash.success)}
                        </p>
                    )}
                    <h1 className="sr-only">{t('Sign in')}</h1>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/login');
                        }}
                    >
                        <div className="user-auth-field">
                            <label className="sr-only" htmlFor="identifier">
                                {t('Email')}
                            </label>
                            <Input
                                id="identifier"
                                type="email"
                                autoComplete="username"
                                inputMode="email"
                                placeholder={t('Email')}
                                value={form.data.identifier}
                                onChange={(event) => form.setData('identifier', event.target.value)}
                                aria-invalid={Boolean(form.errors.identifier)}
                                required
                            />
                        </div>
                        {form.errors.identifier ? (
                            <p className="px-5 text-sm text-danger" role="alert">
                                {errorMessage(form.errors.identifier)}
                            </p>
                        ) : null}
                        <div className="user-auth-field">
                            <label className="sr-only" htmlFor="password">
                                {t('Password')}
                            </label>
                            <Input
                                id="password"
                                className="pr-20!"
                                type={visible ? 'text' : 'password'}
                                autoComplete="current-password"
                                placeholder={t('Password')}
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                aria-invalid={Boolean(form.errors.password)}
                                required
                            />
                            <button
                                type="button"
                                aria-label={visible ? t('Hide password') : t('Show password')}
                                onClick={() => setVisible(!visible)}
                            >
                                {visible ? (
                                    <Eye className="mx-auto size-6" />
                                ) : (
                                    <EyeOff className="mx-auto size-6" />
                                )}
                            </button>
                        </div>
                        {form.errors.password ? (
                            <p className="px-5 text-sm text-danger" role="alert">
                                {errorMessage(form.errors.password)}
                            </p>
                        ) : null}
                        <button
                            className="user-auth-submit"
                            aria-label={t('Sign in')}
                            type="submit"
                            disabled={
                                form.processing || !form.data.identifier || !form.data.password
                            }
                        >
                            {form.processing ? t('Signing in…') : t('Sign in')}
                        </button>
                    </form>
                    <Link
                        className="mt-5 block text-center text-sm text-muted-foreground underline underline-offset-4"
                        href="/forgot-password"
                    >
                        {t('Forgot password?')}
                    </Link>
                    <Link className="user-auth-register" href="/register">
                        {t('Register')}
                    </Link>
                </div>
            </main>
        </div>
    );
}
