import { formatDate } from '@/lib/format';
import type { MessagePart } from '@/types';

/**
 * A notification's sentence as one text. The server writes and translates it; only its dates are put
 * into the reader's own format here.
 */
export function notificationText(message: MessagePart[]): string {
    return message
        .map((part) =>
            typeof part === 'string' ? part : formatDate(part.date),
        )
        .join('');
}
