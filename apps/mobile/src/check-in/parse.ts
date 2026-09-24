import { uuid } from '../api/client';
export type Scan = {
    organization: string;
    session: string;
    credential: string;
};
/** Treat camera input as untrusted data, never as a URL or navigation command. */
export function parseCheckIn(raw: string): Scan | null {
    if (raw.length > 4096 || !raw.startsWith('GTQR1:')) return null;
    try {
        const value: unknown = JSON.parse(raw.slice(6));
        if (!value || typeof value !== 'object' || Array.isArray(value))
            return null;
        const keys = Object.keys(value).sort().join(',');
        if (keys !== 'credential,organization,session') return null;
        const scan = value as Scan;
        if (
            typeof scan.organization !== 'string' ||
            typeof scan.session !== 'string' ||
            typeof scan.credential !== 'string'
        )
            return null;
        uuid(scan.organization);
        uuid(scan.session);
        if (!/^[A-Za-z0-9+/=]{1,2048}$/.test(scan.credential)) return null;
        return scan;
    } catch {
        return null;
    }
}
