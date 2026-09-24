import * as Notifications from 'expo-notifications';
import { DeviceLifecycle } from './device';
// No registration backend/provider is configured in GT-012. Permission is only
// requested after a user action and once an authorized registry is configured.
export const device = new DeviceLifecycle(
    {
        async permission() {
            const result = await Notifications.requestPermissionsAsync();
            return result.granted ? 'granted' : 'denied';
        },
        async token() {
            const result = await Notifications.getDevicePushTokenAsync();
            return String(result.data);
        },
    },
    null,
);
