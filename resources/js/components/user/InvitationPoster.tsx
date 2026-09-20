import { ImageIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { QRCodeCanvas } from 'qrcode.react';
import { t } from '@/i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';

export function InvitationPoster({
    link,
    code,
    background,
}: {
    link: string;
    code: string;
    background: string | null;
}) {
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState('');
    const [failed, setFailed] = useState(false);
    const qr = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!open) return;
        let cancelled = false;
        setPreview('');
        setFailed(false);
        const generate = async () => {
            await document.fonts.ready;
            let picture: HTMLImageElement | null = null;
            if (background) {
                picture = new Image();
                picture.src = background;
                await picture.decode();
            }
            if (cancelled) return;
            const canvas = document.createElement('canvas');
            canvas.width = 900;
            const height = picture
                ? Math.min(1600, Math.max(600, (900 * picture.height) / picture.width))
                : 1000;
            canvas.height = Math.ceil(height);
            const ctx = canvas.getContext('2d');
            const qrCanvas = qr.current?.querySelector('canvas');
            if (!ctx || !qrCanvas) throw new Error('Canvas unavailable');
            ctx.fillStyle = '#163e34';
            ctx.fillRect(0, 0, 900, height);
            if (picture) {
                const scale = Math.min(900 / picture.width, height / picture.height);
                ctx.drawImage(
                    picture,
                    (900 - picture.width * scale) / 2,
                    (height - picture.height * scale) / 2,
                    picture.width * scale,
                    picture.height * scale,
                );
            } else {
                ctx.fillStyle = '#e2d7a9';
                ctx.font = 'bold 60px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(t('Invitation to join'), 450, 420, 800);
                ctx.font = '32px sans-serif';
                ctx.fillText(t('Share your invitation link with a friend.'), 450, 500, 780);
            }
            // Center the QR in the background's reserved bottom space.
            // Keep its white quiet zone and sharp modules for reliable scanning.
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(qrCanvas, 340, height - 330, 220, 220);
            ctx.fillStyle = '#e2d7a9';
            ctx.font = '500 26px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.shadowColor = 'rgba(0, 0, 0, 0.6)';
            ctx.shadowBlur = 6;
            ctx.fillText(t('Scan with your browser'), 450, height - 78, 600);
            if (!cancelled) setPreview(canvas.toDataURL('image/png'));
        };
        void generate().catch(() => {
            if (!cancelled) setFailed(true);
        });
        return () => {
            cancelled = true;
        };
    }, [open, background, code, link]);
    return (
        <>
            <Button className="w-full rounded-full" onClick={() => setOpen(true)}>
                <ImageIcon className="size-4" />
                {t('Invitation poster')}
            </Button>
            <div ref={qr} className="hidden" aria-hidden="true">
                <QRCodeCanvas value={link} size={520} marginSize={4} level="M" />
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('Invitation poster')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Save the poster or press and hold the image to save it on your phone.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {preview ? (
                        <>
                            <img
                                src={preview}
                                alt={t('Invitation poster')}
                                className="w-full rounded-lg"
                            />
                            <div className="flex justify-center">
                                <Button asChild>
                                    <a href={preview} download={`invitation-${code}.png`}>
                                        {t('Save image')}
                                    </a>
                                </Button>
                            </div>
                        </>
                    ) : (
                        <p role="status">
                            {t(
                                failed
                                    ? 'Could not generate poster. Please try again.'
                                    : 'Generating poster…',
                            )}
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
