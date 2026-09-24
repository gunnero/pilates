import { ApiClient, pageQuery, uuid } from './client';
import type {
    CheckInResult,
    Account,
    Organization,
    Page,
    Profile,
    Group,
    Session,
    Waiting,
    Attendance,
    Membership,
    Payment,
    Notice,
    Preferences,
} from './types';
export class MemberApi {
    constructor(readonly client: ApiClient) {}
    account() {
        return this.client.request<{ data: Account }>('/api/v1/member/account');
    }
    organizations(page = 1) {
        return this.client.request<Page<Organization>>(
            `/api/v1/member/organizations?${pageQuery(page)}`,
        );
    }
    login(email: string, password: string) {
        return this.client.request<{
            data: { token: string; expires_at: string };
        }>('/api/v1/member-auth/token', 'POST', {
            email,
            password,
            device_name: 'GT mobile',
        });
    }
    activate(email: string, token: string, password: string) {
        return this.client.request('/api/v1/member-auth/activate', 'POST', {
            email,
            token,
            password,
            password_confirmation: password,
        });
    }
    forgot(email: string) {
        return this.client.request(
            '/api/v1/member-auth/forgot-password',
            'POST',
            { email },
        );
    }
    reset(email: string, token: string, password: string) {
        return this.client.request(
            '/api/v1/member-auth/reset-password',
            'POST',
            { email, token, password, password_confirmation: password },
        );
    }
    password(current_password: string, password: string) {
        return this.client.request('/api/v1/member/password', 'POST', {
            current_password,
            password,
            password_confirmation: password,
        });
    }
    logout() {
        return this.client.request('/api/v1/member/token/logout', 'POST', {});
    }
    base(org: string) {
        return `/api/v1/member/organizations/${uuid(org)}`;
    }
    switch(org: string) {
        return this.client.request(`${this.base(org)}/switch`, 'POST', {});
    }
    profile(org: string, signal?: AbortSignal) {
        return this.client.request<{ data: Profile }>(
            `${this.base(org)}/profile`,
            'GET',
            undefined,
            signal,
        );
    }
    saveLocale(org: string, locale: 'mk' | 'en') {
        return this.client.request(`${this.base(org)}/profile`, 'PATCH', {
            preferred_locale: locale,
        });
    }
    groups(org: string, page = 1, signal?: AbortSignal) {
        return this.client.request<Page<Group>>(
            `${this.base(org)}/groups?${pageQuery(page)}`,
            'GET',
            undefined,
            signal,
        );
    }
    group(org: string, id: string, signal?: AbortSignal) {
        return this.client.request<{ data: Group }>(
            `${this.base(org)}/groups/${uuid(id)}`,
            'GET',
            undefined,
            signal,
        );
    }
    sessions(
        org: string,
        page = 1,
        relationship = 'all',
        group?: string,
        signal?: AbortSignal,
    ) {
        return this.client.request<Page<Session>>(
            `${this.base(org)}/sessions?${pageQuery(page)}&relationship=${encodeURIComponent(relationship)}${group ? `&group_id=${uuid(group)}` : ''}`,
            'GET',
            undefined,
            signal,
        );
    }
    session(org: string, id: string, signal?: AbortSignal) {
        return this.client.request<{ data: Session }>(
            `${this.base(org)}/sessions/${uuid(id)}`,
            'GET',
            undefined,
            signal,
        );
    }
    checkIn(org: string, id: string, credential: string, signal?: AbortSignal) {
        return this.client.request<{ data: CheckInResult }>(
            `${this.base(org)}/sessions/${uuid(id)}/check-in`,
            'POST',
            { credential },
            signal,
        );
    }
    reserve(org: string, id: string, waiting: boolean) {
        return this.client.request<{ data: { outcome: string } }>(
            `${this.base(org)}/sessions/${uuid(id)}/reservations`,
            'POST',
            { allow_waiting: waiting },
        );
    }
    cancel(org: string, kind: 'reservations' | 'waiting-list', id: string) {
        return this.client.request(
            `${this.base(org)}/${kind}/${uuid(id)}/cancel`,
            'POST',
            {},
        );
    }
    waiting(org: string, page = 1, signal?: AbortSignal) {
        return this.client.request<Page<Waiting>>(
            `${this.base(org)}/waiting-list?${pageQuery(page)}`,
            'GET',
            undefined,
            signal,
        );
    }
    attendance(org: string, page = 1, signal?: AbortSignal) {
        return this.client.request<Page<Attendance>>(
            `${this.base(org)}/attendance?${pageQuery(page)}`,
            'GET',
            undefined,
            signal,
        );
    }
    memberships(org: string, page = 1, signal?: AbortSignal) {
        return this.client.request<Page<Membership>>(
            `${this.base(org)}/memberships?${pageQuery(page)}`,
            'GET',
            undefined,
            signal,
        );
    }
    payments(org: string, page = 1, signal?: AbortSignal) {
        return this.client.request<Page<Payment>>(
            `${this.base(org)}/payments?${pageQuery(page)}`,
            'GET',
            undefined,
            signal,
        );
    }
    inboxBase(org: string) {
        return `/api/v1/organizations/${uuid(org)}/notifications`;
    }
    notices(org: string, page = 1, signal?: AbortSignal) {
        return this.client.request<Page<Notice>>(
            `${this.inboxBase(org)}?${pageQuery(page)}`,
            'GET',
            undefined,
            signal,
        );
    }
    notice(org: string, id: string, signal?: AbortSignal) {
        return this.client.request<{ data: Notice }>(
            `${this.inboxBase(org)}/${uuid(id)}`,
            'GET',
            undefined,
            signal,
        );
    }
    read(org: string, ids: string[]) {
        return this.client.request(`${this.inboxBase(org)}/read`, 'POST', {
            ids: ids.slice(0, 25).map(uuid),
        });
    }
    preferences(org: string, signal?: AbortSignal) {
        return this.client.request<{ data: Preferences }>(
            `${this.inboxBase(org)}/preferences`,
            'GET',
            undefined,
            signal,
        );
    }
    savePreferences(org: string, preferences: Preferences) {
        return this.client.request(
            `${this.inboxBase(org)}/preferences`,
            'PATCH',
            preferences,
        );
    }
}
