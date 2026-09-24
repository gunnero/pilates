import { ApiError } from '../api/client';
import type { MemberApi } from '../api/member';
import type { Account, Organization } from '../api/types';
import type { Credentials } from '../storage/credentials';
export type SessionState = {
    phase: 'restoring' | 'anonymous' | 'offline' | 'ready';
    account: Account | null;
    organization: Organization | null;
    revision: number;
};
export class SessionController {
    private epoch = 0;
    private listeners = new Set<() => void>();
    private state: SessionState = {
        phase: 'restoring',
        account: null,
        organization: null,
        revision: 0,
    };
    constructor(
        readonly api: MemberApi,
        private storage: Credentials,
        private clearCache: () => void,
        private cleanupDevice: () => Promise<void>,
    ) {
        api.client.onDenied = (kind, token) => {
            if (!token || token !== api.client.token) return;
            if (kind === 'expired' || !this.state.account)
                void this.clear().catch(() => undefined);
            else this.leaveOrganization();
        };
    }
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    snapshot = () => this.state;
    private publish(
        state: Omit<SessionState, 'revision'> & { revision?: number },
    ) {
        this.state = { ...state, revision: this.state.revision + 1 };
        this.listeners.forEach((listener) => listener());
    }
    async restore() {
        const epoch = ++this.epoch;
        this.clearCache();
        this.publish({ phase: 'restoring', account: null, organization: null });
        try {
            const token = await this.storage.read();
            if (epoch !== this.epoch) return;
            this.api.client.token = token;
            if (!token) {
                this.publish({
                    phase: 'anonymous',
                    account: null,
                    organization: null,
                });
                return;
            }
            const { data: account } = await this.api.account();
            if (epoch === this.epoch)
                this.publish({ phase: 'ready', account, organization: null });
        } catch (error) {
            if (epoch !== this.epoch) return;
            if (error instanceof ApiError && error.kind === 'expired')
                await this.clear();
            else
                this.publish({
                    phase: 'offline',
                    account: null,
                    organization: null,
                });
        }
    }
    async login(email: string, password: string) {
        await this.clear();
        const epoch = ++this.epoch;
        const response = await this.api.login(email, password);
        if (epoch !== this.epoch) return;
        this.api.client.token = response.data.token;
        try {
            await this.storage.write(response.data.token);
        } catch {
            await this.clear();
            throw new ApiError('configuration');
        }
        if (epoch !== this.epoch) return;
        await this.restore();
    }
    leaveOrganization() {
        this.epoch++;
        this.clearCache();
        this.publish({ ...this.state, organization: null });
    }
    async select(organization: Organization) {
        const epoch = ++this.epoch;
        const account = this.state.account;
        this.clearCache();
        this.publish({ ...this.state, organization: null });
        await this.api.switch(organization.id);
        if (epoch === this.epoch && account)
            this.publish({ phase: 'ready', account, organization });
    }
    async clear() {
        this.epoch++;
        this.api.client.token = null;
        this.clearCache();
        this.publish({ phase: 'anonymous', account: null, organization: null });
        await Promise.all([this.storage.clear(), this.cleanupDevice()]);
    }
    async logout() {
        // Start the revocation with the captured bearer, then immediately hide local data.
        const revoke = this.api.logout();
        await Promise.all([revoke, this.clear()]);
    }
}
