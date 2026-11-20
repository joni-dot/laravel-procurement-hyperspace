/// <reference types="node" />

import { createHmac } from 'node:crypto';

const base32Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function decodeBase32(text: string): Buffer {
    const bits = text
        .replace(/=+$/, '')
        .toUpperCase()
        .split('')
        .map((character) => {
            const value = base32Alphabet.indexOf(character);

            if (value === -1) {
                throw new Error(`Not a base32 character: ${character}`);
            }

            return value.toString(2).padStart(5, '0');
        })
        .join('');

    const bytes: number[] = [];

    for (let index = 0; index + 8 <= bits.length; index += 8) {
        bytes.push(parseInt(bits.slice(index, index + 8), 2));
    }

    return Buffer.from(bytes);
}

/**
 * The six-digit code an authenticator app shows right now for this secret
 * (RFC 6238: HMAC-SHA1 over 30-second steps), so tests can pass two-factor
 * sign-in without an extra package.
 */
export function twoFactorCode(secret: string): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)));

    const hash = createHmac('sha1', decodeBase32(secret))
        .update(counter)
        .digest();
    const offset = hash[hash.length - 1] & 0x0f;
    const code = (hash.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}
