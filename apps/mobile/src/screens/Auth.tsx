import { useRef, useState } from 'react';
import { View } from 'react-native';
import { useSession } from '../auth/context';
import { en as opsEn, mk as opsMk } from '../locales/operations';
import { useLocale } from '../locales/context';
import {
    Body,
    Button,
    ErrorMessage,
    Field,
    Heading,
    Screen,
    styles,
} from '../components/ui';
import type { MemberLink } from '../navigation/links';
export function Auth({
    link,
    consumed,
    staffSignIn,
}: {
    link: Extract<MemberLink, { email: string }> | null;
    consumed: () => void;
    staffSignIn?: () => void;
}) {
    const { controller } = useSession();
    const { t, setLocale, locale } = useLocale();
    const [mode, setMode] = useState<'login' | 'activate' | 'forgot' | 'reset'>(
        link?.kind ?? 'login',
    );
    const [email, setEmail] = useState(link?.email ?? '');
    const [token, setToken] = useState(link?.token ?? '');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const locked = useRef(false);
    const [error, setError] = useState<unknown>(null);
    const [message, setMessage] = useState('');
    async function submit() {
        if (locked.current) return;
        locked.current = true;
        setBusy(true);
        setError(null);
        setMessage('');
        try {
            if (mode === 'login') await controller.login(email, password);
            else if (mode === 'forgot') {
                await controller.api.forgot(email);
                setMessage(t('authSent'));
            } else {
                if (mode === 'activate')
                    await controller.api.activate(email, token, password);
                else await controller.api.reset(email, token, password);
                setMessage(t(mode === 'activate' ? 'activated' : 'resetDone'));
                setMode('login');
                setToken('');
                consumed();
            }
        } catch (e) {
            setError(e);
        } finally {
            setPassword('');
            locked.current = false;
            setBusy(false);
        }
    }
    return (
        <Screen headerless>
            <Heading>{t('app')}</Heading>
            {staffSignIn && (
                <Button
                    label={locale === 'mk' ? opsMk.staffMode : opsEn.staffMode}
                    disabled={busy}
                    onPress={staffSignIn}
                />
            )}
            <Body>{t(mode)}</Body>
            <Field label={t('email')} value={email} onChange={setEmail} email />
            {(mode === 'activate' || mode === 'reset') && (
                <Field
                    label={t('token')}
                    value={token}
                    onChange={setToken}
                    secret
                />
            )}
            {mode !== 'forgot' && (
                <Field
                    label={t('password')}
                    value={password}
                    onChange={setPassword}
                    secret
                />
            )}
            <ErrorMessage error={error} />
            {!!message && <Body>{message}</Body>}
            <Button
                label={t(mode)}
                disabled={busy}
                onPress={() => void submit()}
            />
            <View style={styles.row}>
                {(['login', 'activate', 'forgot', 'reset'] as const)
                    .filter((item) => item !== mode)
                    .map((item) => (
                        <Button
                            key={item}
                            label={t(item)}
                            disabled={busy}
                            onPress={() => {
                                setMode(item);
                                setPassword('');
                                setToken('');
                                setError(null);
                                consumed();
                            }}
                        />
                    ))}
            </View>
            <View style={styles.row}>
                <Button
                    label={t('macedonian')}
                    onPress={() => setLocale('mk')}
                />
                <Button label={t('english')} onPress={() => setLocale('en')} />
            </View>
        </Screen>
    );
}
