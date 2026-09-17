import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { t, useClientTranslation } from '@/i18n';

export function IdentityVerificationDialog({
    open,
    onDismiss,
}: {
    open: boolean;
    onDismiss: () => void;
}) {
    useClientTranslation();
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) onDismiss();
            }}
        >
            <DialogContent closeLabel={t('Close')} className="max-w-sm rounded-2xl bg-white">
                <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-amber-100 text-amber-800">
                    <ShieldAlert className="size-6" aria-hidden="true" />
                </div>
                <DialogHeader>
                    <DialogTitle>{t('Complete identity verification')}</DialogTitle>
                    <DialogDescription className="leading-6">
                        {t('Verify your identity before using financial services.')}
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-2">
                    <Button asChild className="bg-amber-700 text-white">
                        <Link href="/kyc">{t('Verify now')}</Link>
                    </Button>
                    <Button variant="ghost" onClick={onDismiss}>
                        {t('Cancel')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
