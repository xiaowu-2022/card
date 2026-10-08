import { createContext, useContext, type ComponentProps } from 'react';
import * as Modal from '@/components/ui/dialog';
import { useEditor } from './editor-context';

const Inline = createContext(false);
export function Dialog(props: ComponentProps<typeof Modal.Dialog>) {
    const editor = useEditor();
    if (!editor) return <Modal.Dialog {...props} />;
    return <Inline.Provider value={true}>{props.open ? props.children : null}</Inline.Provider>;
}
export function DialogContent({
    children,
    className,
    ...props
}: ComponentProps<typeof Modal.DialogContent>) {
    const inline = useContext(Inline);
    return inline ? (
        <section className={`rounded-lg border p-4 ${className ?? ''}`}>{children}</section>
    ) : (
        <Modal.DialogContent {...props} className={className}>
            {children}
        </Modal.DialogContent>
    );
}
export function DialogTitle(props: ComponentProps<typeof Modal.DialogTitle>) {
    const inline = useContext(Inline);
    return inline ? (
        <h3 className="mb-3 text-lg font-semibold">{props.children}</h3>
    ) : (
        <Modal.DialogTitle {...props} />
    );
}
export function DialogDescription(props: ComponentProps<typeof Modal.DialogDescription>) {
    const inline = useContext(Inline);
    return inline ? (
        <p className="text-sm text-muted-foreground">{props.children}</p>
    ) : (
        <Modal.DialogDescription {...props} />
    );
}
export const DialogHeader = Modal.DialogHeader;
