export function arrivalTypeLabel(type?: 'AUTO' | 'MANUAL' | 'ADVANCE' | null): string {
    return type === 'AUTO'
        ? 'Automatic receipt'
        : type === 'ADVANCE'
          ? 'Advance amount'
          : 'Manual confirmation';
}
