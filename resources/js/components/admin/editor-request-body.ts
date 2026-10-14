function append(data: FormData, value: unknown, key: string) {
    if (value instanceof Blob) data.append(key, value);
    else if (Array.isArray(value)) value.forEach((v, i) => append(data, v, `${key}[${i}]`));
    else if (value && typeof value === 'object')
        Object.entries(value).forEach(([k, v]) => append(data, v, key ? `${key}[${k}]` : k));
    else
        data.append(
            key,
            value === true
                ? '1'
                : value === false
                  ? '0'
                  : typeof value === 'string'
                    ? value
                    : typeof value === 'number'
                      ? value.toString()
                      : '',
        );
}

function hasUpload(value: unknown): boolean {
    if (value instanceof Blob) return true;
    if (Array.isArray(value)) return value.some(hasUpload);
    return !!value && typeof value === 'object' && Object.values(value).some(hasUpload);
}

/** JSON preserves empty arrays; multipart is needed only for actual uploads. */
export function editorRequestBody(value: unknown, method: string): {
    body: BodyInit;
    headers: Record<string, string>;
} {
    if (!hasUpload(value)) {
        const data = method === 'post' ? value : { ...(value as object), _method: method.toUpperCase() };
        return { body: JSON.stringify(data), headers: { 'Content-Type': 'application/json' } };
    }
    const body = new FormData();
    append(body, value, '');
    if (method !== 'post') body.append('_method', method.toUpperCase());
    return { body, headers: {} };
}
