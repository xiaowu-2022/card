import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { QuickReplyPicker } from './QuickReplyPicker';
import { supportRequest, SupportRequestError } from './supportRequest';
import { PreviewImage } from '@/components/shared/PreviewImage';
import { useSupportRead } from './useSupportRead';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ImagePlus, MessageSquare, Send, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { dateTime } from '@/i18n';
import { useSupportPolling } from './useSupportPolling';

export type SupportChat = {
    humanSupport?: { available: boolean; timezone: string; nextOpenAt: string | null };
    id: string | null;
    mode?: 'BOT' | 'WAITING' | 'HUMAN';
    revision?: number;
    botEnabled?: boolean;
    accountId?: string;
    before: number;
    olderCursor: number | null;
    messages: {
        id: string;
        sequence: number;
        fromSupport: boolean;
        senderKind?: 'BOT' | 'ADMIN' | 'USER' | 'SUPPORT_AGENT';
        supportName?: string | null;
        text: string;
        createdAt: string;
        imageUrl: string | null;
        imageSources?: string[];
        deleted?: boolean;
        edited?: boolean;
    }[];
};

export function SupportThread({
    chat,
    admin = false,
    t,
    baseUrl,
    canSend = true,
    workspace = false,
    quickReplyUrl,
}: {
    chat: SupportChat;
    admin?: boolean;
    baseUrl?: string;
    canSend?: boolean;
    workspace?: boolean;
    quickReplyUrl?: string;
    t: (key: string) => string;
}) {
    const base = baseUrl ?? (admin ? `/admin/support/${chat.id}` : '/support');
    const form = useForm<{
        request_id: string;
        support_message: string;
        support_image: File | null;
    }>({
        request_id: crypto.randomUUID(),
        support_message: '',
        support_image: null,
    });
    const transition = useForm({ request_id: crypto.randomUUID(), revision: chat.revision ?? 0 });
    const [finishing, setFinishing] = useState(false);
    const [finishError, setFinishError] = useState('');
    const transitionIntent = useRef<{ request_id: string; revision: number } | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [enlarged, setEnlarged] = useState<string | null>(null);
    const [enlargedSources, setEnlargedSources] = useState<string[]>([]);
    const [failed, setFailed] = useState(false);
    const thread = useRef<HTMLElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const messages = useRef<HTMLDivElement>(null);
    const followLatest = useRef(true);
    const disconnected = useSupportPolling(
        workspace ? 'workspace' : 'chat',
        !form.processing && !transition.processing && !finishing && chat.before === 0,
    );
    const lastId = chat.messages.at(-1)?.id;
    useSupportRead(chat.messages.at(-1)?.sequence ?? 0, !admin);
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
    const navigate = (before: number) => {
        const filters = workspace
            ? Object.fromEntries(new URLSearchParams(window.location.search))
            : {};
        delete filters.before;
        router.get(
            base,
            { ...filters, ...(before ? { before } : {}) },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <section ref={thread} className={`support-thread ${admin ? 'support-thread-admin' : ''}`}>
            <p className="support-privacy">
                {t(
                    'Do not send passwords, verification codes, full card numbers, CVV or identity documents.',
                )}
            </p>
            {chat.mode && (
                <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
                    <span>
                        {t(
                            chat.mode === 'BOT'
                                ? 'Bot support'
                                : chat.mode === 'WAITING'
                                  ? 'Waiting for human support'
                                  : 'Human support',
                        )}
                    </span>
                    {admin && canSend && chat.id && chat.mode !== 'BOT' && chat.before === 0 && (
                        <Button
                            variant="secondary"
                            disabled={form.processing || transition.processing || finishing}
                            onClick={() => {
                                void (async () => {
                                    transitionIntent.current ??= {
                                        request_id: crypto.randomUUID(),
                                        revision: chat.revision ?? 0,
                                    };
                                    setFinishing(true);
                                    setFinishError('');
                                    try {
                                        await supportRequest(
                                            base + '/finish',
                                            transitionIntent.current,
                                        );
                                        transitionIntent.current = null;
                                        router.reload({ only: ['chat', 'inbox'] });
                                    } catch (error) {
                                        if (
                                            error instanceof SupportRequestError &&
                                            error.status === 409
                                        ) {
                                            transitionIntent.current = null;
                                            setFinishError(
                                                t(
                                                    'Conversation changed. Refresh and read the latest messages before ending service.',
                                                ),
                                            );
                                            router.reload({ only: ['chat', 'inbox'] });
                                        } else
                                            setFinishError(t('Unable to save. Please try again.'));
                                    } finally {
                                        setFinishing(false);
                                    }
                                })();
                            }}
                        >
                            {t('End service')}
                        </Button>
                    )}
                    {!admin && chat.mode === 'BOT' && (
                        <Button
                            variant="secondary"
                            disabled={
                                form.processing ||
                                transition.processing ||
                                finishing ||
                                chat.humanSupport?.available === false
                            }
                            onClick={() =>
                                transition.post(base + '/handoff', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        transition.setData('request_id', crypto.randomUUID()),
                                })
                            }
                        >
                            {t('Talk to a person')}
                        </Button>
                    )}
                </div>
            )}
            {chat.humanSupport?.available === false && (
                <p role="status" className="text-sm text-amber-800">
                    {t('Customer support is currently offline.')}{' '}
                    {chat.humanSupport.nextOpenAt
                        ? `${t('Next service time')}: ${new Intl.DateTimeFormat(undefined, { timeZone: chat.humanSupport.timezone, dateStyle: 'short', timeStyle: 'short' }).format(new Date(chat.humanSupport.nextOpenAt))} (${chat.humanSupport.timezone})`
                        : t('No upcoming service hours are scheduled.')}
                </p>
            )}
            {finishError && (
                <OperationFeedback role="alert" className="support-error">
                    {finishError}
                </OperationFeedback>
            )}
            {Object.values(transition.errors).map((error) => (
                <OperationFeedback role="alert" key={error}>
                    {error}
                </OperationFeedback>
            ))}
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
                            className={`support-message ${message.senderKind !== 'BOT' && message.fromSupport === admin ? 'support-message-own' : ''}`}
                            key={message.id}
                        >
                            <p className="support-sender">
                                {message.senderKind === 'BOT'
                                    ? t('Support assistant')
                                    : message.fromSupport
                                      ? message.supportName || t('Customer support')
                                      : admin
                                        ? t('Customer')
                                        : t('You')}
                            </p>
                            <div className="support-bubble">
                                {message.imageUrl && (
                                    <button
                                        type="button"
                                        className="support-image-button"
                                        onClick={() => {
                                            setEnlarged(message.imageUrl);
                                            setEnlargedSources(message.imageSources ?? []);
                                        }}
                                        aria-label={t('View image')}
                                    >
                                        <PreviewImage
                                            sources={message.imageSources}
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
                                {message.deleted ? (
                                    <p>{t('Message deleted')}</p>
                                ) : (
                                    message.text && <p>{message.text}</p>
                                )}
                                {message.edited && !message.deleted && <small>{t('Edited')}</small>}
                            </div>
                            <time dateTime={message.createdAt}>{dateTime(message.createdAt)}</time>
                        </article>
                    ))
                )}
            </div>
            {canSend && quickReplyUrl && (
                <QuickReplyPicker
                    url={quickReplyUrl}
                    disabled={form.processing || finishing}
                    onPick={(body) => {
                        const input = thread.current?.querySelector('textarea');
                        const start = input?.selectionStart ?? form.data.support_message.length;
                        const end = input?.selectionEnd ?? start;
                        const value =
                            form.data.support_message.slice(0, start) +
                            body +
                            form.data.support_message.slice(end);
                        if (value.length > 2000) return false;
                        form.setData('support_message', value);
                        requestAnimationFrame(() => {
                            input?.focus();
                            input?.setSelectionRange(start + body.length, start + body.length);
                        });
                        return true;
                    }}
                />
            )}
            {canSend && (
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
                        <OperationFeedback className="support-error" role="alert">
                            {errors.support_image
                                ? t('Use a JPG, PNG or WebP image up to 5 MB and 20 megapixels.')
                                : t('Message not confirmed. Your draft is kept; please retry.')}
                        </OperationFeedback>
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
                                        !['image/jpeg', 'image/png', 'image/webp'].includes(
                                            file.type,
                                        ))
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
            )}
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
                        <PreviewImage
                            sources={enlargedSources}
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
