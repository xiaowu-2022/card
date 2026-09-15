import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    tenantArticles,
    type TenantArticleContent,
    type TenantArticleKey,
} from '@/lib/tenant-articles';

export function TenantArticleSettings({
    articles,
    locales,
}: {
    articles: TenantArticleContent[];
    locales: string[];
}) {
    useAdminTranslation();
    const [selectedArticle, setSelectedArticle] = useState<string>('terms');
    const [selectedLocale, setSelectedLocale] = useState('zh-CN');
    return (
        <Card className="max-w-4xl">
            <CardHeader>
                <CardTitle>{t('About us articles')}</CardTitle>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Manage these three articles for your company only. Each content language is saved separately.',
                    )}
                </p>
            </CardHeader>
            <CardContent className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField id="article-kind" label={t('Article')}>
                        <Select value={selectedArticle} onValueChange={setSelectedArticle}>
                            <SelectTrigger id="article-kind">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {tenantArticles.map((article) => (
                                    <SelectItem key={article.key} value={article.key}>
                                        {t(article.title)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField id="article-language" label={t('Content language')}>
                        <Select value={selectedLocale} onValueChange={setSelectedLocale}>
                            <SelectTrigger id="article-language">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {locales.map((locale) => (
                                    <SelectItem key={locale} value={locale}>
                                        {t(locale)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Only enabled customer languages are displayed. Missing translations show an unavailable message, not another language.',
                    )}
                </p>
                {selectedArticle === 'account-closure' && (
                    <p className="rounded-lg bg-muted p-3 text-sm">
                        {t(
                            'This is an account closure information article only. Saving it does not close accounts or move funds.',
                        )}
                    </p>
                )}
                {/* Keep editors mounted: article/content/Admin language switches preserve unsaved text. */}
                {tenantArticles.flatMap((article) =>
                    locales.map((locale) => (
                        <div
                            key={`${article.key}:${locale}`}
                            hidden={selectedArticle !== article.key || selectedLocale !== locale}
                        >
                            <ArticleEditor
                                articleKey={article.key}
                                locale={locale}
                                body={
                                    articles.find(
                                        (item) =>
                                            item.key === article.key && item.locale === locale,
                                    )?.body ?? ''
                                }
                            />
                        </div>
                    )),
                )}
            </CardContent>
        </Card>
    );
}

function ArticleEditor({
    articleKey,
    locale,
    body,
}: {
    articleKey: TenantArticleKey;
    locale: string;
    body: string;
}) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const form = useForm({ body });
    const id = `article-body-${articleKey}-${locale}`;
    return (
        <ConfigurationForm
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(configurationUrl(`/admin/settings/articles/${articleKey}/${locale}`), {
                    preserveScroll: true,
                    onSuccess: () => form.setDefaults(),
                });
            }}
        >
            <FormField
                id={id}
                label={t('Article content')}
                description={t(
                    'Plain text only, up to 50,000 characters. Line breaks are preserved. Saving takes effect immediately; saving empty text removes this language version from view.',
                )}
                error={errorMessage(form.errors.body)}
            >
                <Textarea
                    id={id}
                    lang={locale}
                    rows={18}
                    maxLength={50000}
                    value={form.data.body}
                    disabled={form.processing}
                    aria-invalid={Boolean(form.errors.body)}
                    aria-describedby={form.errors.body ? `${id}-error` : undefined}
                    onChange={(event) => form.setData('body', event.target.value)}
                    className="resize-y leading-7"
                />
            </FormField>
            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {t('Save article')}
                </Button>
                {form.isDirty && (
                    <span className="text-sm text-muted-foreground">{t('Unsaved changes')}</span>
                )}
                {form.recentlySuccessful && (
                    <span className="text-sm text-success" role="status">
                        {t('Article saved.')}
                    </span>
                )}
            </div>
        </ConfigurationForm>
    );
}
