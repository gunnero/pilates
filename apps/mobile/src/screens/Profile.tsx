import { useEffect, useState } from 'react';
import { Alert } from 'react-native';
import { useMember } from '../auth/context';
import { useAction, useMemberQuery } from '../hooks/member';
import { useLocale } from '../locales/context';
import { Body, Button, Card, Field } from '../components/ui';
import { ActionFeedback, QueryScreen } from './Domain';
import { device } from '../notifications/native';
export function Profile() {
    const { api, organization, controller, account } = useMember();
    const { t, setLocale } = useLocale();
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [push, setPush] = useState('');
    const action = useAction();
    const query = useMemberQuery(['profile'], async (signal) => {
        const [profile, preferences] = await Promise.all([
            api.profile(organization.id, signal),
            api.preferences(organization.id, signal),
        ]);
        return { profile: profile.data, preferences: preferences.data };
    });
    useEffect(() => {
        if (query.data)
            setLocale(
                query.data.profile.preferred_locale === 'en' ? 'en' : 'mk',
            );
    }, [query.data, setLocale]);
    return (
        <QueryScreen title="profile" query={query}>
            {(data) => (
                <>
                    <Card>
                        <Body>
                            {data.profile.first_name} {data.profile.last_name}
                        </Body>
                        <Body>
                            {t('account')}: {account.email}
                        </Body>
                        <Body>
                            {t('profileEmail')}: {data.profile.email ?? '—'}
                        </Body>
                        <Body>{data.profile.phone ?? '—'}</Body>
                    </Card>
                    <ActionFeedback action={action} />
                    <Button
                        label={t('macedonian')}
                        disabled={action.busy}
                        onPress={() =>
                            void action.run(async () => {
                                await api.saveLocale(organization.id, 'mk');
                                api.client.locale = 'mk';
                                setLocale('mk');
                            })
                        }
                    />
                    <Button
                        label={t('english')}
                        disabled={action.busy}
                        onPress={() =>
                            void action.run(async () => {
                                await api.saveLocale(organization.id, 'en');
                                api.client.locale = 'en';
                                setLocale('en');
                            })
                        }
                    />
                    <Body>{t('preferences')}</Body>
                    {(
                        [
                            'reminders_enabled',
                            'expiry_reminders_enabled',
                        ] as const
                    ).map((key) => (
                        <Button
                            key={key}
                            label={`${t(key === 'reminders_enabled' ? 'reminders' : 'expiryReminders')}: ${t(data.preferences[key] ? 'enabled' : 'disabled')}`}
                            disabled={action.busy}
                            onPress={() =>
                                void action.run(() =>
                                    api.savePreferences(organization.id, {
                                        ...data.preferences,
                                        [key]: !data.preferences[key],
                                    }),
                                )
                            }
                        />
                    ))}
                    <Button
                        label={t('push')}
                        onPress={() =>
                            void device
                                .enable()
                                .then((result) =>
                                    setPush(
                                        t(
                                            result === 'disabled'
                                                ? 'pushDisabled'
                                                : result === 'denied'
                                                  ? 'pushDenied'
                                                  : 'pushReady',
                                        ),
                                    ),
                                )
                                .catch(() => setPush(t('network')))
                        }
                    />
                    {!!push && <Body>{push}</Body>}
                    <Field
                        label={t('currentPassword')}
                        value={current}
                        onChange={setCurrent}
                        secret
                    />
                    <Field
                        label={t('newPassword')}
                        value={password}
                        onChange={setPassword}
                        secret
                    />
                    <Button
                        label={t('changePassword')}
                        disabled={action.busy}
                        onPress={() =>
                            void action.run(async () => {
                                try {
                                    await api.password(current, password);
                                    await controller.clear();
                                } finally {
                                    setCurrent('');
                                    setPassword('');
                                }
                            })
                        }
                    />
                    <Button
                        label={t('switchStudio')}
                        onPress={() => {
                            controller.leaveOrganization();
                        }}
                    />
                    <Button
                        label={t('logout')}
                        onPress={() => {
                            Alert.alert(t('logout'), undefined, [
                                { text: t('back'), style: 'cancel' },
                                {
                                    text: t('logout'),
                                    style: 'destructive',
                                    onPress: () =>
                                        void controller
                                            .logout()
                                            .catch(() =>
                                                Alert.alert(t('logoutOffline')),
                                            ),
                                },
                            ]);
                        }}
                    />
                </>
            )}
        </QueryScreen>
    );
}
