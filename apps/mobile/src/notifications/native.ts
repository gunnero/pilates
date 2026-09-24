import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';
import { Platform } from 'react-native';
import { ApiClient } from '../api/client';
import type { MemberApi } from '../api/member';
import { DeviceLifecycle } from './device';

const projectId = process.env.EXPO_PUBLIC_EAS_PROJECT_ID;
export const device = new DeviceLifecycle(
    {
        async permission() {
            if (Platform.OS === 'android')
                await Notifications.setNotificationChannelAsync('default', {
                    name: 'GT Studio',
                    importance: Notifications.AndroidImportance.DEFAULT,
                });
            const result = await Notifications.requestPermissionsAsync();
            return result.granted ? 'granted' : 'denied';
        },
        async token() {
            const result = await Notifications.getExpoPushTokenAsync({
                projectId,
            });
            return result.data;
        },
    },
    null,
);
export function bindDevice(api: MemberApi, organization: string) {
    if (!projectId || !api.client.token) return () => {};
    // Keep cleanup bound to the original credential, even after app logout/switch.
    const client = new ApiClient(api.client.origin);
    client.token = api.client.token;
    const ids = new Map<string, string>();
    device.configure({
        async register(token) {
            let installation = await SecureStore.getItemAsync(
                'gt.push.installation',
            );
            if (!installation) {
                installation = Crypto.randomUUID();
                await SecureStore.setItemAsync(
                    'gt.push.installation',
                    installation,
                );
            }
            const response = await client.request<{ data: { id: string } }>(
                `/api/v1/member/organizations/${organization}/devices`,
                'POST',
                { token, installation_id: installation, platform: Platform.OS },
            );
            ids.set(token, response.data.id);
        },
        async revoke(token) {
            const id = ids.get(token);
            if (id)
                await client.request(
                    `/api/v1/member/organizations/${organization}/devices/${id}`,
                    'DELETE',
                    {},
                );
            ids.delete(token);
        },
    });
    let active = true;
    void Notifications.getPermissionsAsync()
        .then((permission) => {
            if (active && permission.granted)
                void device.enable().catch(() => undefined);
        })
        .catch(() => undefined);
    const listener = Notifications.addPushTokenListener(() => {
        void device.enable().catch(() => undefined);
    });
    return () => {
        active = false;
        listener.remove();
        device.configure(null);
    };
}
