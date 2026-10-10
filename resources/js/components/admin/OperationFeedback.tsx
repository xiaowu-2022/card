import { Children, isValidElement, useEffect, type HTMLAttributes, type ReactNode } from 'react';
import { isPlatform, showOperationResult } from './operation-result';

function textContent(node: ReactNode): string {
    return Children.toArray(node)
        .map((child) => {
            if (typeof child === 'string' || typeof child === 'number') return String(child);
            if (isValidElement<{ children?: ReactNode }>(child))
                return textContent(child.props.children);
            return '';
        })
        .join('');
}

/** Operation feedback is portalled by the app host; shared consumer/tenant uses stay inline. */
export function OperationFeedback({
    children,
    kind = 'error',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { kind?: 'success' | 'error' }) {
    const message = textContent(children);
    useEffect(() => {
        if (message) showOperationResult(kind, message);
    }, [kind, message]);
    return isPlatform() ? null : <p {...props}>{children}</p>;
}
