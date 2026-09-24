import { useEffect, useRef, useState } from 'react';
import { AppState, Text } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useIsFocused, useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useQueryClient } from '@tanstack/react-query';
import { useMember } from '../auth/context';
import { useLocale } from '../locales/context';
import type { TextKey } from '../locales/strings';
import {
    Body,
    Button,
    Card,
    ErrorMessage,
    Heading,
    Loading,
    Screen,
    styles,
} from '../components/ui';
import { parseCheckIn } from '../check-in/parse';
import { ApiError } from '../api/client';
import type { CheckInResult } from '../api/types';
import type { Routes } from '../navigation/types';
import { memberKey } from '../hooks/cache';
import { dateTime } from '../utils/format';
const reasons: Record<string, TextKey> = {
    qr_invalid: 'qr_invalid',
    qr_expired: 'qr_expired',
    too_early: 'too_early',
    too_late: 'too_late',
    waiting: 'waitingQr',
    reservation_required: 'reservation_required',
    cancelled: 'cancelledQr',
    unavailable: 'unavailableQr',
    member_ineligible: 'member_ineligible',
    correction_required: 'correction_required',
};
export function CheckIn() {
    const { api, organization, account } = useMember();
    const { t, locale } = useLocale();
    const navigation = useNavigation<NativeStackNavigationProp<Routes>>();
    const cache = useQueryClient();
    const focused = useIsFocused();
    const [active, setActive] = useState(AppState.currentState === 'active');
    const [permission, requestPermission] = useCameraPermissions();
    const [cameraFailed, setCameraFailed] = useState(false);
    const [started, setStarted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<TextKey | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [result, setResult] = useState<CheckInResult | null>(null);
    const [session, setSession] = useState<string | null>(null);
    const locked = useRef(false);
    const alive = useRef(true);
    const request = useRef<AbortController | null>(null);
    useEffect(() => {
        alive.current = true;
        const subscription = AppState.addEventListener('change', (state) =>
            setActive(state === 'active'),
        );
        return () => {
            alive.current = false;
            request.current?.abort();
            subscription.remove();
        };
    }, []);
    async function scan(raw: string) {
        if (!alive.current || locked.current || !focused || !active || !started)
            return;
        locked.current = true;
        setStarted(false);
        const parsed = parseCheckIn(raw);
        if (!parsed) {
            setMessage('scanInvalid');
            return;
        }
        if (parsed.organization !== organization.id) {
            setMessage('scanWrongStudio');
            return;
        }
        setSession(parsed.session);
        setBusy(true);
        request.current = new AbortController();
        try {
            const response = await api.checkIn(
                organization.id,
                parsed.session,
                parsed.credential,
                request.current.signal,
            );
            if (alive.current) setResult(response.data);
        } catch (e) {
            if (alive.current) {
                if (e instanceof ApiError && e.code && reasons[e.code])
                    setMessage(reasons[e.code]);
                else setError(e);
            }
        } finally {
            if (alive.current) {
                setBusy(false);
                void cache.invalidateQueries({
                    queryKey: memberKey(account.id, organization.id),
                });
            }
        }
    }
    const ambiguous = error instanceof ApiError && error.kind === 'network';
    return (
        <Screen>
            <Heading>{t('checkIn')}</Heading>
            <Body>{t('cameraWhy')}</Body>
            <Body>{t('cameraFallback')}</Body>
            {busy && (
                <>
                    <Loading />
                    <Body>{t('scanVerify')}</Body>
                </>
            )}
            {message && (
                <Text
                    accessibilityRole="alert"
                    accessibilityLiveRegion="polite"
                    style={styles.error}
                >
                    {t(message)}
                </Text>
            )}
            <ErrorMessage error={error} />
            {ambiguous && <Body>{t('scanUncertain')}</Body>}
            {result && (
                <Card>
                    <Heading>
                        {t(
                            result.outcome === 'already_present'
                                ? 'scanDuplicate'
                                : 'scanSuccess',
                        )}
                    </Heading>
                    <Body>{result.session.name}</Body>
                    <Body>
                        {dateTime(
                            result.session.starts_at,
                            result.session.timezone,
                            locale,
                        )}{' '}
                        ({result.session.timezone})
                    </Body>
                    <Body>{t('present')}</Body>
                </Card>
            )}
            {session && !busy && (
                <Button
                    label={t('scanSession')}
                    onPress={() =>
                        navigation.navigate('Session', { id: session })
                    }
                />
            )}
            {started &&
                permission?.granted &&
                focused &&
                active &&
                !cameraFailed && (
                    <CameraView
                        style={{ height: 300, width: '100%' }}
                        facing="back"
                        barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
                        onBarcodeScanned={({ data }) => void scan(data)}
                        onMountError={() => {
                            setCameraFailed(true);
                            setStarted(false);
                        }}
                    />
                )}
            {!started &&
                !busy &&
                !cameraFailed &&
                !ambiguous &&
                (permission?.granted || permission?.canAskAgain !== false) && (
                    <Button
                        label={t(
                            permission?.granted ? 'scanAgain' : 'cameraEnable',
                        )}
                        onPress={() => {
                            void (async () => {
                                try {
                                    const allowed =
                                        permission?.granted ||
                                        (await requestPermission()).granted;
                                    if (!allowed || !alive.current) return;
                                    locked.current = false;
                                    setMessage(null);
                                    setError(null);
                                    setResult(null);
                                    setSession(null);
                                    setStarted(true);
                                } catch {
                                    if (alive.current) setCameraFailed(true);
                                }
                            })();
                        }}
                    />
                )}
        </Screen>
    );
}
