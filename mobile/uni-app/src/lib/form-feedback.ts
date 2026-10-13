import {
    computed,
    inject,
    nextTick,
    onBeforeUnmount,
    provide,
    shallowRef,
    watch,
    type InjectionKey,
    type Ref,
} from 'vue';

type Field = { names: () => string[]; element: () => unknown };
type Feedback = { errors: Ref<Record<string, string>>; fields: Ref<Field[]> };
const feedbackKey: InjectionKey<Feedback> = Symbol('form-feedback');

/** Field registration stays inside this form, including fields in teleported dialogs. */
export function useFormFeedback(errors: Ref<Record<string, string>>) {
    const fields = shallowRef<Field[]>([]);
    let edits = 0,
        submittedAt: number | null = null;
    const feedback = {
        errors,
        fields,
        begin: () => {
            submittedAt = edits;
        },
    };
    // #ifdef H5
    provide(feedbackKey, feedback);
    let generation = 0;
    const edited = (event: Event) => {
        if (
            fields.value.some((field) =>
                domElement(field.element())?.contains(event.target as Node),
            )
        )
            edits++;
    };
    document.addEventListener('input', edited, true);
    watch(
        [errors, () => fields.value.length],
        async ([value]) => {
            const run = ++generation;
            if (!Object.keys(value).length || document.hidden) return;
            const active = document.activeElement;
            // A slow response must never steal focus while the user edits another field.
            if (
                submittedAt !== null
                    ? edits !== submittedAt
                    : active?.matches('input,textarea,[contenteditable="true"]')
            )
                return;
            await nextTick();
            if (
                run !== generation ||
                document.hidden ||
                document.activeElement !== active ||
                (submittedAt !== null && edits !== submittedAt)
            )
                return;
            const elements = fields.value
                .filter((field) => field.names().some((name) => !!value[name]))
                .map((field) => domElement(field.element()))
                .filter((el): el is HTMLElement => !!el && el.getClientRects().length > 0)
                .sort((a, b) =>
                    a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING ? 1 : -1,
                );
            const target = elements[0];
            if (!target) return;
            const scroller = target.closest('.modal-scroll') as HTMLElement | null;
            if (scroller) {
                const delta =
                    target.getBoundingClientRect().top - scroller.getBoundingClientRect().top - 12;
                scroller.scrollTop += delta;
            } else target.scrollIntoView({ block: 'center', behavior: 'auto' });
            const input = target.querySelector<HTMLInputElement>(
                'input:not(:disabled),textarea:not(:disabled)',
            );
            input?.focus({ preventScroll: true });
        },
        { deep: true, flush: 'post' },
    );
    onBeforeUnmount(() => {
        generation++;
        document.removeEventListener('input', edited, true);
    });
    // #endif
    return feedback;
}
function domElement(value: any): HTMLElement | null {
    // #ifdef H5
    const element = value?.$el ?? value;
    return element instanceof HTMLElement ? element : null;
    // #endif
    return null;
}
export function useFeedbackField(names: () => string[], element: () => unknown) {
    const feedback = inject(feedbackKey, null);
    const field = { names, element };
    if (feedback) feedback.fields.value = [...feedback.fields.value, field];
    onBeforeUnmount(() => {
        if (feedback)
            feedback.fields.value = feedback.fields.value.filter((item) => item !== field);
    });
    return computed(() =>
        [
            ...new Set(
                names()
                    .map((name) => feedback?.errors.value[name])
                    .filter(Boolean),
            ),
        ].join('\n'),
    );
}
export function useFeedbackSummary(errors: () => Record<string, string>) {
    const feedback = inject(feedbackKey, null);
    return computed(() =>
        Object.fromEntries(
            Object.entries(errors()).filter(
                ([name]) =>
                    feedback?.errors.value !== errors() ||
                    !feedback.fields.value.some((field) => field.names().includes(name)),
            ),
        ),
    );
}
