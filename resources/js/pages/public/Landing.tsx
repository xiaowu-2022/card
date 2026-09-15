import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Aperture,
    ArrowDown,
    ArrowRight,
    ArrowUpRight,
    CreditCard,
    Fingerprint,
    List,
    Menu,
    Plus,
    Wallet,
} from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { LanguageSwitcher } from '@/components/user/LanguageSwitcher';
import type { SharedProps } from '@/types/global';
import { userThemeStyle } from '@/lib/user-theme';

const services = [
    {
        icon: Wallet,
        title: 'Wallet',
        copy: 'View your available balance and follow each top-up and withdrawal.',
        href: '/wallet',
    },
    {
        icon: CreditCard,
        title: 'Card management',
        copy: 'Manage your cards from one place, with controls available for each card.',
        href: '/cards',
    },
    {
        icon: List,
        title: 'Transaction history',
        copy: 'Review card activity and keep track of individual transactions.',
        href: '/cards',
    },
    {
        icon: Fingerprint,
        title: 'Identity verification',
        copy: 'Submit your identity materials through your account before applying.',
        href: '/kyc',
    },
] as const;
const steps = [
    {
        title: 'Create your account',
        copy: 'Use an invitation code and verify your email or phone number.',
    },
    {
        title: 'Complete your information',
        copy: 'Complete identity verification and the requirements shown in your account.',
    },
    {
        title: 'Apply for your card',
        copy: 'Review the available product, fees and cardholder information before confirming.',
    },
] as const;
const faqs = [
    { question: 'What do I need to register?', answer: steps[0].copy },
    {
        question: 'How do I apply for a Mastercard U Card?',
        answer: steps[1].copy + ' ' + steps[2].copy,
    },
    {
        question: 'Where can I see fees and limits?',
        answer: 'Fees, limits and product availability are shown in your account. Please review the current information before confirming an operation.',
    },
    {
        question: 'When will a top-up arrive?',
        answer: 'Timing depends on network confirmations and payment verification. Track the actual status in your account; no fixed arrival time is guaranteed.',
    },
    {
        question: 'Can I manage cards from my phone?',
        answer: 'Use the web account on your phone or computer to view cards and the available management controls.',
    },
] as const;
const showcases = [
    services[1],
    services[2],
    {
        title: 'Cardholder information',
        copy: 'Review and update cardholder information through the available card controls.',
    },
] as const;

function CardArtwork({
    brand,
    tone = 'green',
}: {
    brand: string;
    tone?: 'green' | 'red' | 'dark';
}) {
    return (
        <div className={`marketing-card marketing-card-${tone}`} aria-hidden="true">
            <Aperture className="marketing-card-symbol" />
            <span className="marketing-card-brand">{brand}</span>
            <div className="marketing-card-orbit" />
            <div className="marketing-card-bottom">
                <CreditCard />
                <span>{t('Mastercard U Card')}</span>
            </div>
        </div>
    );
}
function HeroCardArtwork() {
    return (
        <div className="marketing-hero-card" aria-hidden="true">
            <img
                className="marketing-hero-card-artwork"
                src="/images/marketing/spec-pay-gold-world.png"
                alt=""
                width={1586}
                height={992}
                fetchPriority="high"
                decoding="async"
            />
        </div>
    );
}

function SectionHeading({ first, second, copy }: { first: string; second: string; copy?: string }) {
    return (
        <div className="marketing-section-heading">
            <h2>
                {t(first)}
                <br />
                <span>{t(second)}</span>
            </h2>
            {copy && <p>{t(copy)}</p>}
        </div>
    );
}

export default function Landing() {
    useClientTranslation();
    useLocaleSync();
    const { tenant } = usePage<SharedProps>().props;
    const brand = tenant?.branding.brandName ?? 'Aperture Cards';
    const [menuOpen, setMenuOpen] = useState(false);
    const [active, setActive] = useState(0);
    const currentShowcase = showcases[active] ?? showcases[0];
    const navigation = [
        { title: 'Home', href: '#home' },
        { title: 'Products', href: '#products' },
        { title: 'Card management', href: '#manage' },
        { title: 'Getting started', href: '#start' },
        { title: 'FAQ', href: '#faq' },
    ];
    const brandMark = (
        <>
            {tenant?.branding.logoUrl ? (
                <img src={tenant.branding.logoUrl} alt={brand} />
            ) : (
                <>
                    <Aperture aria-hidden="true" />
                    <span>{brand}</span>
                </>
            )}
        </>
    );
    return (
        <div
            className="marketing-home"
            id="home"
            style={userThemeStyle(tenant?.branding.primaryColor)}
        >
            <Head title={`${brand} · ${t('Global payments, in your hands.')}`} />
            <div className="marketing-notice">
                {t('Protect your account. Never share your password or verification code.')}
            </div>
            <header className="marketing-header">
                <Link href="/" className="marketing-brand">
                    {brandMark}
                </Link>
                <nav className="marketing-desktop-nav" aria-label={t('Public navigation')}>
                    {navigation.map((item) => (
                        <a href={item.href} key={item.href}>
                            {t(item.title)}
                        </a>
                    ))}
                </nav>
                <div className="marketing-header-actions">
                    <Link href="/login" className="marketing-login">
                        {t('Log in')} <ArrowUpRight size={15} />
                    </Link>
                    <Button asChild className="marketing-pill" variant="secondary">
                        <Link href="/register">
                            {t('Register')} <ArrowRight size={15} />
                        </Link>
                    </Button>
                    <LanguageSwitcher variant="icon" />
                    <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="marketing-menu"
                                aria-label={t('Open navigation')}
                            >
                                <Menu />
                            </Button>
                        </SheetTrigger>
                        <SheetContent closeLabel={t('Close menu')}>
                            <SheetTitle>{brand}</SheetTitle>
                            <SheetDescription className="sr-only">
                                {t('Public navigation')}
                            </SheetDescription>
                            <nav className="mt-8 grid gap-5">
                                {navigation.map((item) => (
                                    <a
                                        key={item.href}
                                        href={item.href}
                                        onClick={() => setMenuOpen(false)}
                                    >
                                        {t(item.title)}
                                    </a>
                                ))}
                                <Link href="/login">{t('Log in')}</Link>
                            </nav>
                        </SheetContent>
                    </Sheet>
                </div>
            </header>
            <main>
                <section className="marketing-hero">
                    <div className="marketing-hero-inner">
                        <div className="marketing-hero-copy">
                            <h1 className={brand.length > 12 ? 'marketing-long-brand' : ''}>
                                {brand}
                            </h1>
                            <p>{t('Global payments, in your hands.')}</p>
                        </div>
                        <div className="marketing-hero-art">
                            <HeroCardArtwork />
                        </div>
                        <div className="marketing-hero-action">
                            <Button asChild className="marketing-cta">
                                <Link href="/login">
                                    {t('Explore your account')} <ArrowUpRight size={18} />
                                </Link>
                            </Button>
                        </div>
                    </div>
                    <div className="marketing-hero-footer">
                        <a
                            className="marketing-scroll"
                            href="#products"
                            aria-label={t('Explore products')}
                        >
                            <ArrowDown size={20} />
                        </a>
                        <span className="marketing-art-note">{t('Card design illustration')}</span>
                    </div>
                </section>
                <div className="marketing-service-strip">
                    <p>{t('Your account. Your cards. One place.')}</p>
                    <div>
                        {services.map(({ icon: Icon, title }) => (
                            <span key={title}>
                                <Icon />
                                {t(title)}
                            </span>
                        ))}
                    </div>
                </div>
                <section id="products" className="marketing-section marketing-muted">
                    <SectionHeading
                        first="A simpler way to"
                        second="manage your cards."
                        copy="Explore the tools available in your account. Product access depends on eligibility and service availability."
                    />
                    <div className="marketing-products marketing-container">
                        <Link href="/cards" className="marketing-product-feature">
                            <div className="marketing-feature-art">
                                <CardArtwork brand={brand} tone="dark" />
                            </div>
                            <div>
                                <CreditCard size={38} />
                                <h3>{t('Mastercard U Card')}</h3>
                                <p>
                                    {t(
                                        'Review available card products and apply from your account.',
                                    )}
                                </p>
                                <ArrowUpRight />
                            </div>
                        </Link>
                        {services.map(({ icon: Icon, title, copy, href }) => (
                            <Link href={href} className="marketing-product" key={title}>
                                <Icon />
                                <h3>{t(title)}</h3>
                                <p>{t(copy)}</p>
                                <ArrowUpRight className="marketing-product-arrow" />
                            </Link>
                        ))}
                    </div>
                </section>
                <section id="manage" className="marketing-section">
                    <SectionHeading
                        first="Your card."
                        second="A clearer view."
                        copy="Keep card details, activity and available controls together."
                    />
                    <div className="marketing-showcase marketing-container">
                        <div className="marketing-showcase-copy">
                            <span className="marketing-step-number">0{active + 1} / 03</span>
                            <h3>{t(currentShowcase.title)}</h3>
                            <p>{t(currentShowcase.copy)}</p>
                            <Link href="/cards">
                                {t('View your cards')} <ArrowUpRight />
                            </Link>
                            <div
                                className="marketing-showcase-tabs"
                                aria-label={t('Card management')}
                            >
                                {showcases.map(({ title }, index) => (
                                    <button
                                        key={title}
                                        aria-pressed={active === index}
                                        aria-label={t(title)}
                                        onClick={() => setActive(index)}
                                    >
                                        0{index + 1}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="marketing-showcase-art" aria-hidden="true">
                            {active === 0 ? (
                                <CardArtwork brand={brand} />
                            ) : (
                                <div className="marketing-ui-art">
                                    {active === 1 ? <List /> : <Fingerprint />}
                                    <div className="marketing-ui-line" />
                                    <div className="marketing-ui-line" />
                                    <div className="marketing-ui-line" />
                                    <div className="marketing-ui-line short" />
                                </div>
                            )}
                        </div>
                    </div>
                </section>
                <section id="start" className="marketing-section marketing-container">
                    <SectionHeading first="Getting started" second="begins with you." />
                    <div className="marketing-steps">
                        {steps.map((step, index) => (
                            <div key={step.title}>
                                <span>0{index + 1}</span>
                                <h3>{t(step.title)}</h3>
                                <p>{t(step.copy)}</p>
                            </div>
                        ))}
                    </div>
                    <div className="marketing-start-action">
                        <Button asChild className="marketing-cta">
                            <Link href="/register">
                                {t('Create your account')} <ArrowRight />
                            </Link>
                        </Button>
                    </div>
                </section>
                <section id="faq" className="marketing-section marketing-muted">
                    <SectionHeading first="Frequently asked" second="questions." />
                    <div className="marketing-faq">
                        {faqs.map(({ question, answer }) => (
                            <details key={question}>
                                <summary>
                                    {t(question)}
                                    <Plus aria-hidden="true" />
                                </summary>
                                <p>{t(answer)}</p>
                            </details>
                        ))}
                    </div>
                </section>
            </main>
            <footer className="marketing-footer">
                <div className="marketing-container marketing-footer-grid">
                    <div>
                        <Link className="marketing-brand" href="/">
                            {brandMark}
                        </Link>
                        <p>{t('Global payments, in your hands.')}</p>
                    </div>
                    <div>
                        <h3>{t('Products')}</h3>
                        <Link href="/cards">{t('Mastercard U Card')}</Link>
                        <Link href="/wallet">{t('Wallet')}</Link>
                    </div>
                    <div>
                        <h3>{t('Help')}</h3>
                        <a href="#faq">{t('FAQ')}</a>
                        <a href="#start">{t('Getting started')}</a>
                    </div>
                    <div>
                        <h3>{t('Your account')}</h3>
                        <Link href="/login">{t('Log in')}</Link>
                        <Link href="/register">{t('Register')}</Link>
                    </div>
                    <div>
                        <h3>{t('About us')}</h3>
                        <Link href="/about/terms">{t('Terms of service')}</Link>
                        <Link href="/about/privacy">{t('Privacy policy')}</Link>
                    </div>
                </div>
                <div className="marketing-container marketing-footer-bottom">
                    <p>{`© ${new Date().getFullYear()} ${brand}`}</p>
                    <p>
                        {t(
                            'Service availability and processing results are shown in your account. An application is not a guarantee of approval.',
                        )}
                    </p>
                </div>
            </footer>
        </div>
    );
}
