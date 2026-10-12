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
    role?: 'owner' | 'administrator' | 'trainer' | 'staff';
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
    trainer_profile?: { id: string; status: 'active' | 'inactive' } | null;
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

export type TrainerProfile = {
    id: string;
    membership_id: string;
    display_name: string;
    display_name_override: string | null;
    bio?: string | null;
    status: 'active' | 'inactive';
    staff_role: StaffMembership['role'];
    staff_status: StaffMembership['status'];
    location_scope: 'all' | 'selected';
    assignable: boolean;
    eligible_location_count: number;
    eligible_locations: { id: string; name: string }[];
};

export type ScheduleRule = {
    id: string;
    weekday: number;
    start_time: string;
    end_time: string;
    effective_from: string;
    effective_until: string | null;
    canceled: boolean;
};
export type TrainingGroup = {
    id: string;
    name: string;
    description?: string | null;
    capacity: number;
    status: 'active' | 'inactive' | 'archived';
    location: { id: string; name: string; status: 'active' | 'inactive' };
    primary_trainer: { id: string; display_name: string } | null;
    timezone: string;
    eligible_for_scheduling: boolean;
    operational_issues: string[];
    schedule_summary?: ScheduleRule[];
    schedule_rule_count?: number;
};

export type TrainingSession = {
    attendance_open?: boolean;
    id: string;
    group: { id: string; name: string };
    location: { id: string; name: string };
    trainer: { id: string; display_name: string };
    local_date: string;
    start_time: string;
    end_time: string;
    starts_at: string;
    ends_at: string;
    timezone: string;
    capacity: number;
    reserved_count?: number;
    available_spots?: number;
    waiting_count?: number;
    status: 'scheduled' | 'cancelled';
    source_type: 'recurring' | 'manual';
    is_overridden: boolean;
    cancellation_reason: string | null;
    cancellation_kind: 'manual' | 'schedule' | null;
    operational_issues: string[];
    editable: boolean;
};
