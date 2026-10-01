import { useEffect, useEffectEvent, useMemo, useState } from 'react';
import { Alert, AppState, Linking } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import {
    NavigationContainer,
    createNavigationContainerRef,
} from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { focusManager, QueryClientProvider } from '@tanstack/react-query';
import * as Notifications from 'expo-notifications';
import { ApiClient, apiOrigin } from './api/client';
import { MemberApi } from './api/member';
import { SessionController } from './auth/session';
import { SessionProvider, useSession } from './auth/context';
import { credentials } from './storage/credentials';
import { createCache } from './hooks/cache';
import { LocaleProvider, useLocale } from './locales/context';
import { Body, Button, Heading, Loading, Screen } from './components/ui';
import { StaffOperations } from './screens/StaffOperations';
import { Auth } from './screens/Auth';
import { Organizations } from './screens/Organizations';
import {
    Activity,
    AttendanceHistory,
    GroupDetail,
    Groups,
    Home,
    Inbox,
    Memberships,
    NoticeDetail,
    Payments,
    SessionDetail,
    Sessions,
    WaitingList,
} from './screens/Domain';
import { CheckIn } from './screens/CheckIn';
import { Profile } from './screens/Profile';
import { parseLink, parsePushLink, type MemberLink } from './navigation/links';
import type { Routes } from './navigation/types';
import { bindDevice, device } from './notifications/native';
const Stack = createNativeStackNavigator<Routes>();
const navigation = createNavigationContainerRef<Routes>();
function Flow() {
    const { controller, phase, account, organization } = useSession();
    const { t, locale, setLocale } = useLocale();
    useEffect(() => {
        if (!organization || !account) return;
        return bindDevice(controller.api, organization.id);
    }, [controller, organization, account]);
    const [staffMode, setStaffMode] = useState(false);
    const [link, setLink] = useState<MemberLink | null>(null);
    useEffect(() => {
        controller.api.client.setLocale(locale);
    }, [controller, locale]);
    useEffect(() => {
        void controller.restore();
    }, [controller]);
    useEffect(() => {
        if (!organization) return;
        let active = true;
        void controller.api
            .profile(organization.id)
            .then(({ data }) => {
                if (active)
                    setLocale(data.preferred_locale === 'en' ? 'en' : 'mk');
            })
            .catch(() => undefined);
        return () => {
            active = false;
        };
    }, [controller, organization, setLocale]);
    const receive = useEffectEvent((url: string) => {
        const parsed = parseLink(url);
        if (
            !parsed ||
            ('email' in parsed && controller.snapshot().phase === 'ready')
        ) {
            Alert.alert(t('invalidLink'));
            return;
        }
        setLink(parsed);
        if (
            'organization' in parsed &&
            organization &&
            parsed.organization !== organization.id
        ) {
            controller.api.client.onDenied(
                'forbidden',
                controller.api.client.token,
            );
        }
    });
    const rejectLink = useEffectEvent(() => Alert.alert(t('invalidLink')));
    const receivePush = useEffectEvent((raw: unknown) => {
        if (typeof raw === 'string' && parsePushLink(raw)) receive(raw);
        else rejectLink();
    });
    useEffect(() => {
        void Linking.getInitialURL()
            .then((url) => {
                if (url) receive(url);
            })
            .catch(() => rejectLink());
        const subscription = Linking.addEventListener('url', ({ url }) =>
            receive(url),
        );
        void Notifications.getLastNotificationResponseAsync()
            .then((response) => {
                if (response) {
                    receivePush(
                        response.notification.request.content.data?.url,
                    );
                    void Notifications.clearLastNotificationResponseAsync().catch(
                        () => undefined,
                    );
                }
            })
            .catch(() => undefined);
        const push = Notifications.addNotificationResponseReceivedListener(
            (response) => {
                receivePush(response.notification.request.content.data?.url);
                void Notifications.clearLastNotificationResponseAsync().catch(
                    () => undefined,
                );
            },
        );
        return () => {
            subscription.remove();
            push.remove();
        };
    }, []);
    useEffect(() => {
        if (
            link &&
            'organization' in link &&
            organization?.id === link.organization &&
            navigation.isReady()
        ) {
            navigation.navigate(
                link.kind === 'session' ? 'Session' : 'Notice',
                { id: link.id },
            );
        }
    }, [link, organization]);
    if (phase === 'restoring')
        return (
            <Screen headerless>
                <Loading />
            </Screen>
        );
    if (phase === 'offline')
        return (
            <Screen headerless>
                <Body>{t('offlineSession')}</Body>
                <Button
                    label={t('retry')}
                    onPress={() => void controller.restore()}
                />
                <Button
                    label={t('logout')}
                    onPress={() => void controller.clear()}
                />
            </Screen>
        );
    if (phase === 'anonymous' && staffMode)
        return (
            <StaffOperations
                origin={controller.api.client.origin}
                exit={() => setStaffMode(false)}
            />
        );
    if (phase === 'anonymous')
        return (
            <>
                <Auth
                    key={link && 'email' in link ? link.token : 'login'}
                    link={link && 'email' in link ? link : null}
                    consumed={() => setLink(null)}
                    staffSignIn={() => setStaffMode(true)}
                />
            </>
        );
    if (!organization)
        return (
            <>
                <Organizations />
                {link && 'organization' in link && (
                    <Body>{t('wrongStudio')}</Body>
                )}
            </>
        );
    return (
        <NavigationContainer
            ref={navigation}
            key={`${account!.id}:${organization.id}`}
            onStateChange={() => {
                if (
                    link &&
                    'organization' in link &&
                    link.organization === organization.id
                )
                    setLink(null);
            }}
            onReady={() => {
                if (
                    link &&
                    'organization' in link &&
                    link.organization === organization.id
                ) {
                    navigation.navigate(
                        link.kind === 'session' ? 'Session' : 'Notice',
                        { id: link.id },
                    );
                    setLink(null);
                }
            }}
        >
            <Stack.Navigator
                initialRouteName="Home"
                screenOptions={{
                    headerStyle: { backgroundColor: '#f5f4ef' },
                    headerTintColor: '#173a38',
                    headerBackTitle: t('back'),
                }}
            >
                <Stack.Screen
                    name="CheckIn"
                    component={CheckIn}
                    options={{ title: t('checkIn') }}
                />
                <Stack.Screen
                    name="Home"
                    component={Home}
                    options={{ title: t('home') }}
                />
                <Stack.Screen
                    name="Sessions"
                    component={Sessions}
                    options={{ title: t('sessions') }}
                />
                <Stack.Screen
                    name="Session"
                    component={SessionDetail}
                    options={{ title: t('details') }}
                />
                <Stack.Screen
                    name="Groups"
                    component={Groups}
                    options={{ title: t('groups') }}
                />
                <Stack.Screen
                    name="Group"
                    component={GroupDetail}
                    options={{ title: t('groups') }}
                />
                <Stack.Screen
                    name="Activity"
                    component={Activity}
                    options={{ title: t('activity') }}
                />
                <Stack.Screen
                    name="Waiting"
                    component={WaitingList}
                    options={{ title: t('waiting') }}
                />
                <Stack.Screen
                    name="Attendance"
                    component={AttendanceHistory}
                    options={{ title: t('attendance') }}
                />
                <Stack.Screen
                    name="Memberships"
                    component={Memberships}
                    options={{ title: t('memberships') }}
                />
                <Stack.Screen
                    name="Payments"
                    component={Payments}
                    options={{ title: t('payments') }}
                />
                <Stack.Screen
                    name="Notifications"
                    component={Inbox}
                    options={{ title: t('notifications') }}
                />
                <Stack.Screen
                    name="Notice"
                    component={NoticeDetail}
                    options={{ title: t('notifications') }}
                />
                <Stack.Screen
                    name="Profile"
                    component={Profile}
                    options={{ title: t('profile') }}
                />
            </Stack.Navigator>
        </NavigationContainer>
    );
}
function Bootstrap() {
    const { t } = useLocale();
    const runtime = useMemo(() => {
        try {
            const origin = apiOrigin(
                process.env.EXPO_PUBLIC_API_URL,
                __DEV__,
                process.env.EXPO_PUBLIC_ALLOW_LOCAL_HTTP === 'true',
            );
            const cache = createCache();
            const controller = new SessionController(
                new MemberApi(new ApiClient(origin)),
                credentials,
                () => {
                    void cache.cancelQueries();
                    cache.clear();
                },
                () => device.clear(),
            );
            return { cache, controller };
        } catch {
            return null;
        }
    }, []);
    useEffect(() => {
        const listener = AppState.addEventListener('change', (state) => {
            focusManager.setFocused(state === 'active');
            if (state !== 'active') return;
            if (runtime?.controller.snapshot().phase === 'offline')
                void runtime.controller.restore();
        });
        return () => listener.remove();
    }, [runtime]);
    if (!runtime)
        return (
            <Screen headerless>
                <Heading>{t('app')}</Heading>
                <Body>{t('configuration')}</Body>
            </Screen>
        );
    return (
        <QueryClientProvider client={runtime.cache}>
            <SessionProvider controller={runtime.controller}>
                <Flow />
            </SessionProvider>
        </QueryClientProvider>
    );
}
export default function App() {
    return (
        <SafeAreaProvider>
            <LocaleProvider>
                <Bootstrap />
            </LocaleProvider>
        </SafeAreaProvider>
    );
}
