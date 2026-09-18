import i18n from './i18n';
export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors?: Record<string, string[]>,
    ) {
        super(message);
    }
}
async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(path, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'Accept-Language': i18n.language,
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
            ...options.headers,
        },
    });
    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        throw new ApiError(
            response.status,
            payload.message ?? i18n.t('error'),
            payload.errors,
        );
    }
    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
}
export const api = {
    get: <T>(path: string, signal?: AbortSignal) =>
        request<T>(path, { signal }),
    async patch<T>(path: string, data: unknown): Promise<T> {
        await request<void>('/sanctum/csrf-cookie');
        return request<T>(path, {
            method: 'PATCH',
            body: JSON.stringify(data),
        });
    },
    async post<T = void>(
        path: string,
        data: unknown = {},
        signal?: AbortSignal,
    ): Promise<T> {
        await request<void>('/sanctum/csrf-cookie', { signal });
        return request<T>(path, {
            method: 'POST',
            body: JSON.stringify(data),
            signal,
        });
    },
};
export type Profile = {
    id: string;
    name: string;
    organizations: { id: string; name: string }[];
    active_organization_id: string | null;
};
export type Organization = {
    id: string;
    name: string;
    locale: string;
    timezone: string;
    currency: string;
    slug: string;
    status: 'active' | 'inactive' | 'suspended';
    permissions: string[];
    membership_id: string;
    location_scope: 'all' | 'selected';
};

export type Location = {
    id: string;
    name: string;
    address: string | null;
    city: string | null;
    country_code: string | null;
    status: 'active' | 'inactive';
};
export type StaffMembership = {
    id: string;
    user: { id: string; name: string };
    role: 'owner' | 'administrator' | 'trainer' | 'staff';
    status: 'active' | 'suspended' | 'revoked';
    permissions?: string[];
    location_scope: 'all' | 'selected';
    location_ids?: string[];
};
export type Page<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};
export function errorMessage(error: unknown): string {
    return error instanceof ApiError
        ? Object.values(error.errors ?? {})
              .flat()
              .join(' ') || error.message
        : i18n.t('error');
}

export type StudioMember = {
    id: string;
    first_name: string;
    last_name: string;
    phone: string | null;
    email: string | null;
    status: 'active' | 'inactive' | 'archived';
    digital_access_mode: 'app' | 'assisted' | 'offline';
    preferred_locale: 'mk' | 'en';
    preferred_location?: { id: string; name: string; status: string } | null;
    notes?: string | null;
    created_at: string;
    updated_at: string;
};
