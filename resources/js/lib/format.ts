// Made once because the package list formats thousands of dates, and toLocaleDateString() is several times slower.
const dateFormat = new Intl.DateTimeFormat();

/**
 * Shows a date-only value such as "2026-10-29" in the user's own date format.
 * The parts are read one by one so the date never shifts because of time zones.
 */
export function formatDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

export function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Shows a stored amount such as "120000.00" with its currency, in the user's own number format.
 */
export function formatMoney(amount: string, currencyCode: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currencyCode,
    }).format(Number(amount));
}

/**
 * Shows a moment such as "2026-11-03T09:15:00+00:00" in the user's own time zone and format.
 */
export function formatDateTime(isoDateTime: string): string {
    return new Date(isoDateTime).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const shortDateFormat = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
});

/**
 * Shows a date-only value with its day and month only, such as "9 Nov", for labels with little room.
 */
export function formatShortDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-').map(Number);

    return shortDateFormat.format(new Date(year, month - 1, day));
}
