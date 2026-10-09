import { ApiError, UploadError } from './api';
import { errorMessage, t } from './i18n';

// Keep the operation and document side, but never display file paths, image URLs,
// raw gateway pages, OCR output or unknown server messages.
export function explainKycSubmissionError(error: unknown, documentType: string): Record<string, string> {
    const api = error instanceof ApiError ? error : null;
    const stage = error instanceof UploadError ? error.stage : 'submit';
    const document = t(error instanceof UploadError && error.field === 'back'
        ? 'ID back' : documentType === 'PASSPORT' ? 'Passport information page' : 'ID front');
    const titles = {
        read: 'Unable to read {{document}}.',
        prepare: 'Unable to prepare the document upload.',
        upload: 'Unable to upload {{document}}.',
        verify: 'Unable to confirm the upload of {{document}}.',
        submit: 'Identity verification submission was not confirmed.',
    };
    const fallback = 'The service did not provide a specific reason. Please contact support if this continues.';
    const status = api?.status ?? 0;
    const code = api?.payload?.error?.code;
    let reason: string;
    if (code === 'CLIENT_IMAGE_FORMAT' || status === 415) {
        reason = t('This image format is not supported. Select a JPEG, PNG or WEBP image.');
    } else if (stage === 'read') {
        reason = t('The selected photo could not be opened. Please select it again or take a new photo.');
    } else if (status === 401) {
        reason = t('Please sign in to continue.');
    } else if (status === 419) {
        reason = t('Your session has expired. Please refresh and try again.');
    } else if (status === 429) {
        reason = t('Too many attempts. Please try again later.');
    } else if (status === 410) {
        reason = t('The document upload has expired. Please select and upload the photos again.');
    } else if (status === 409) {
        reason = t('The verification or upload status has changed. Refresh status before trying again.');
    } else if (status === 413) {
        reason = t('The image exceeds the server upload size limit. Please use a smaller image.');
    } else if (status === 0 || status === 408 || status === 504) {
        reason = t(code === 'CLIENT_REQUEST_TIMEOUT' || status === 408 || status === 504
            ? 'The request timed out. Please check your connection.'
            : 'No response was received. Please check your connection.');
    } else if (status >= 500) {
        reason = t('The document service is temporarily unavailable. Please try again later or contact support.');
    } else {
        const details = Object.entries(api?.payload?.errors ?? {}).flatMap(([field, messages]) => {
            const message = Array.isArray(messages) ? messages[0] : messages;
            if (typeof message !== 'string') return [];
            let detail: string;
            const max = message.match(/^The (?:file|front|back) field must not be greater than (\d+) kilobytes\.$/);
            if (max) detail = t('The image must not exceed {{size}} KB. Please select a smaller image.', { size: max[1] });
            else if (/^The (?:file|front|back) field must be a file of type:/.test(message))
                detail = t('This image format is not supported. Select a JPEG, PNG or WEBP image.');
            else detail = errorMessage(message, fallback);
            const label = ({ front: documentType === 'PASSPORT' ? 'Passport information page' : 'ID front', back: 'ID back',
                document_type: 'Document type', document_country: 'Document country' } as Record<string, string>)[field];
            return [label ? t('{{field}}: {{reason}}', { field: t(label), reason: detail }) : detail];
        });
        reason = [...new Set(details)].join('\n') || errorMessage(api?.payload?.error?.message, fallback)
            || t(status === 403 ? 'Document submission is not permitted for this account. Refresh your status or contact support.'
                : status === 422 ? 'The document information was not accepted. Please check the selected photos and document details.' : fallback);
    }
    const result: Record<string, string> = { operation: t(titles[stage], { document }), reason };
    if (stage === 'submit' && (status === 0 || status === 408 || status >= 500))
        result.next = t('The application may have been received. Refresh status before submitting again.');
    return result;
}
