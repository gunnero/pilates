export type Account = { id: string; name: string; email: string };
export type Organization = {
    id: string;
    name: string;
    timezone: string;
    locale: string;
    currency: string;
};
export type Page<T> = {
    data: T[];
    meta: { current_page: number; last_page: number };
    unread_count?: number;
};
export type Profile = {
    id: string;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    preferred_locale: 'mk' | 'en';
};
export type Group = {
    id: string;
    name: string;
    description: string | null;
    capacity: number;
    location: { id: string; name: string };
    trainer: { id: string; name: string } | null;
    timezone: string;
    schedule: {
        weekday: number;
        start_minute: number;
        end_minute: number;
        effective_from: string;
        effective_until: string | null;
    }[];
};
export type Session = {
    id: string;
    group: { id: string; name: string };
    location: { id: string; name: string };
    trainer: { id: string; name: string } | null;
    starts_at: string;
    ends_at: string;
    local_date: string;
    timezone: string;
    status: string;
    capacity: number;
    available_spots: number;
    can_reserve: boolean;
    can_join_waiting: boolean;
    attendance_status: string;
    reservation: {
        id: string;
        status: string;
        source: string;
        can_cancel: boolean;
    } | null;
    waiting_entry: { id: string; status: string; can_cancel: boolean } | null;
};
export type Waiting = {
    id: string;
    status: string;
    session_id: string;
    session_name: string;
    starts_at: string;
    timezone: string;
    can_cancel: boolean;
};
export type CheckInResult = {
    outcome: 'checked_in' | 'already_present';
    attendance: {
        id: string;
        status: 'present';
        method: string;
        checked_in_at: string;
    };
    session: { id: string; name: string; starts_at: string; timezone: string };
};
export type Attendance = {
    method: string;
    id: string;
    status: string;
    checked_in_at: string | null;
    session: { id: string; name: string; starts_at: string; timezone: string };
};
export type Membership = {
    id: string;
    plan_name: string;
    starts_on: string;
    ends_on: string;
    status: string;
    agreed_price_minor: number;
    currency: string;
    net_paid_minor: number;
    balance_minor: number;
};
export type Payment = {
    id: string;
    membership_id: string;
    amount_minor: number;
    currency: string;
    paid_at: string;
    status: string;
    reversal: { id: string; amount_minor: number; created_at: string } | null;
};
export type Notice = {
    id: string;
    title: string;
    message: string;
    read_at: string | null;
    created_at: string;
    current: boolean;
};
export type Preferences = {
    reminders_enabled: boolean;
    expiry_reminders_enabled: boolean;
};
