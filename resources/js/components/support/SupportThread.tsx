import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ImagePlus, MessageSquare, Send, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { dateTime } from '@/i18n';
import { useSupportPolling } from './useSupportPolling';

export type SupportChat = {
    id: string | null;
    accountId?: string;
    before: number;
    olderCursor: number | null;
    messages: {
        id: string;
        sequence: number;
        fromSupport: boolean;
        text: string;
        createdAt: string;
        imageUrl: string | null;
    }[];
};

export function SupportThread({
    chat,
    admin = false,
    t,
}: {
    chat: SupportChat;
    admin?: boolean;
    t: (key: string) => string;
}) {
    const base = admin ? `/admin/support/${chat.id}` : '/support';
    const form = useForm<{
        request_id: string;
        support_message: string;
        support_image: File | null;
    }>({
        request_id: crypto.randomUUID(),
        support_message: '',
        support_image: null,
    });
    const [preview, setPreview] = useState<string | null>(null);
    const [enlarged, setEnlarged] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);
    const messages = useRef<HTMLDivElement>(null);
    const followLatest = useRef(true);
    const disconnected = useSupportPolling('chat', !form.processing && chat.before === 0);
    const lastId = chat.messages.at(-1)?.id;
    useEffect(() => {
        if (!form.data.support_image) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(form.data.support_image);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [form.data.support_image]);
    useEffect(() => {
        if (messages.current && followLatest.current && chat.before === 0) {
            messages.current.scrollTop = messages.current.scrollHeight;
        }
    }, [lastId, chat.before]);
    const errors = form.errors as Record<string, string>;
    const navigate = (before: number) =>
        router.get(base, before ? { before } : {}, { preserveState: true, preserveScroll: true });

    return (
        <section className={`support-thread ${admin ? 'support-thread-admin' : ''}`}>
            <p className="support-privacy">
                {t(
                    'Do not send passwords, verification codes, full card numbers, CVV or identity documents.',
                )}
            </p>
            {disconnected && (
                <p role="status" className="support-error">
                    {t('Connection interrupted. Reconnecting… Your draft is saved on this page.')}
                </p>
            )}
            <div className="support-history-actions">
                {chat.olderCursor && (
                    <Button variant="ghost" onClick={() => navigate(chat.olderCursor!)}>
                        {t('Earlier messages')}
                    </Button>
                )}
                {chat.before > 0 && (
                    <Button variant="secondary" onClick={() => navigate(0)}>
                        {t('Latest messages')}
                    </Button>
                )}
            </div>
            <div
                ref={messages}
                className="support-messages"
                role="log"
                aria-label={t('Chat history')}
                aria-live="polite"
                onScroll={() => {
                    const box = messages.current;
                    if (box)
                        followLatest.current =
                            box.scrollHeight - box.scrollTop - box.clientHeight < 80;
                }}
            >
                {chat.messages.length === 0 ? (
                    <div className="support-empty">
                        <MessageSquare aria-hidden="true" />
                        <h2>{t('How can we help?')}</h2>
                        <p>
                            {t(
                                'Send a message or image. Your company support team can reply here.',
                            )}
                        </p>
                    </div>
                ) : (
                    chat.messages.map((message) => (
                        <article
                            className={`support-message ${message.fromSupport === admin ? 'support-message-own' : ''}`}
                            key={message.id}
                        >
                            <p className="support-sender">
                                {message.fromSupport
                                    ? t('Customer support')
                                    : admin
                                      ? t('Customer')
                                      : t('You')}
                            </p>
                            <div className="support-bubble">
                                {message.imageUrl && (
                                    <button
                                        type="button"
                                        className="support-image-button"
                                        onClick={() => setEnlarged(message.imageUrl)}
                                        aria-label={t('View image')}
                                    >
                                        <img
                                            src={message.imageUrl}
                                            alt={t('Chat image')}
                                            loading="lazy"
                                            onLoad={() => {
                                                if (
                                                    followLatest.current &&
                                                    messages.current &&
                                                    chat.before === 0
                                                )
                                                    messages.current.scrollTop =
                                                        messages.current.scrollHeight;
                                            }}
                                        />
                                    </button>
                                )}
                                {message.text && <p>{message.text}</p>}
                            </div>
                            <time dateTime={message.createdAt}>{dateTime(message.createdAt)}</time>
                        </article>
                    ))
                )}
            </div>
            <form
                className="support-composer"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (
                        form.processing ||
                        (!form.data.support_message.trim() && !form.data.support_image)
                    )
                        return;
                    setFailed(false);
                    followLatest.current = true;
                    form.post(`${base}/messages`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.setData({
                                request_id: crypto.randomUUID(),
                                support_message: '',
                                support_image: null,
                            });
                            form.clearErrors();
                            if (fileInput.current) fileInput.current.value = '';
                        },
                        onError: () => setFailed(true),
                        onNetworkError: () => {
                            setFailed(true);
                            return false;
                        },
                        onHttpException: () => {
                            setFailed(true);
                            return false;
                        },
                    });
                }}
            >
                {preview && (
                    <div className="support-preview">
                        <img src={preview} alt={t('Image preview')} />
                        <Button
                            type="button"
                            size="icon"
                            variant="secondary"
                            aria-label={t('Remove image')}
                            disabled={form.processing}
                            onClick={() => {
                                form.setData((data) => ({
                                    ...data,
                                    request_id: crypto.randomUUID(),
                                    support_image: null,
                                }));
                                if (fileInput.current) fileInput.current.value = '';
                            }}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                )}
                <label htmlFor="support-message" className="sr-only">
                    {t('Message')}
                </label>
                <Textarea
                    id="support-message"
                    maxLength={2000}
                    placeholder={t('Write your message…')}
                    value={form.data.support_message}
                    disabled={form.processing}
                    onChange={(event) =>
                        form.setData((data) => ({
                            ...data,
                            request_id: crypto.randomUUID(),
                            support_message: event.target.value,
                        }))
                    }
                />
                {(failed || errors.support_image || errors.support_message || errors.form) && (
                    <p className="support-error" role="alert">
                        {errors.support_image
                            ? t('Use a JPG, PNG or WebP image up to 5 MB and 20 megapixels.')
                            : t('Message not confirmed. Your draft is kept; please retry.')}
                    </p>
                )}
                <div className="support-composer-actions">
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="sr-only"
                        tabIndex={-1}
                        aria-label={t('Attach image')}
                        disabled={form.processing}
                        onChange={(event) => {
                            const file = event.target.files?.[0] ?? null;
                            if (
                                file &&
                                (file.size > 5 * 1024 * 1024 ||
                                    !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))
                            ) {
                                form.setData((data) => ({
                                    ...data,
                                    request_id: crypto.randomUUID(),
                                    support_image: null,
                                }));
                                form.setError('support_image', 'invalid');
                                event.target.value = '';
                                return;
                            }
                            form.clearErrors('support_image');
                            form.setData((data) => ({
                                ...data,
                                request_id: crypto.randomUUID(),
                                support_image: file,
                            }));
                        }}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => fileInput.current?.click()}
                        disabled={form.processing}
                    >
                        <ImagePlus className="size-5" />
                        {t('Attach image')}
                    </Button>
                    <span className="support-limit">{t('Up to 5 MB')}</span>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            (!form.data.support_message.trim() && !form.data.support_image)
                        }
                    >
                        <Send className="size-4" />
                        {form.processing ? t('Sending…') : t('Send message')}
                    </Button>
                </div>
                {form.progress && (
                    <p className="support-upload-progress" role="status">
                        {t('Uploading image…')} {form.progress.percentage}%
                    </p>
                )}
            </form>
            <Dialog
                open={enlarged !== null}
                onOpenChange={(open) => {
                    if (!open) setEnlarged(null);
                }}
            >
                <DialogContent
                    closeLabel={t('Close')}
                    className="max-w-3xl"
                    aria-describedby={undefined}
                >
                    <DialogTitle>{t('Chat image')}</DialogTitle>
                    {enlarged && (
                        <img
                            className="mt-4 max-h-[75dvh] w-full object-contain"
                            src={enlarged}
                            alt={t('Chat image')}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
