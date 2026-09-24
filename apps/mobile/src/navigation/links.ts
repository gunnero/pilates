import { uuid } from '../api/client';
export type MemberLink =
    | { kind: 'activate' | 'reset'; email: string; token: string }
    | { kind: 'session' | 'notification'; organization: string; id: string };
export function parseLink(raw: string): MemberLink | null {
    if (raw.length > 2048) return null;
    try {
        const url = new URL(raw);
        if (
            url.protocol !== 'gtstudio:' ||
            url.username ||
            url.password ||
            url.port ||
            url.search
        )
            return null;
        if (url.hostname === 'activate' || url.hostname === 'reset') {
            if (url.pathname && url.pathname !== '/') return null;
            const fields = new URLSearchParams(url.hash.slice(1));
            const email = fields.get('email') ?? '';
            const token = fields.get('token') ?? '';
            if (
                [...fields.keys()].length !== 2 ||
                !/^[^\s@]{1,128}@[^\s@]{1,126}$/.test(email) ||
                !/^[a-zA-Z0-9]{64}$/.test(token)
            )
                return null;
            return { kind: url.hostname, email, token };
        }
        if (url.hash) return null;
        const segments = url.pathname.split('/').filter(Boolean);
        if (
            (url.hostname === 'session' || url.hostname === 'notification') &&
            segments.length === 2
        )
            return {
                kind: url.hostname,
                organization: uuid(segments[0]),
                id: uuid(segments[1]),
            };
    } catch {
        /* Invalid external input is intentionally never logged. */
    }
    return null;
}

// Provider data is navigation-only; authentication capability links are never push destinations.
export function parsePushLink(
    raw: unknown,
): Extract<MemberLink, { organization: string }> | null {
    const link = typeof raw === 'string' ? parseLink(raw) : null;
    return link && 'organization' in link ? link : null;
}
