import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSession } from '../auth/context';
import { useLocale } from '../locales/context';
import {
    Body,
    Button,
    Card,
    ErrorMessage,
    Heading,
    Loading,
    Pager,
    Screen,
} from '../components/ui';
export function Organizations() {
    const { controller, account } = useSession();
    const { t } = useLocale();
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const query = useQuery({
        queryKey: ['organizations', account?.id, page],
        queryFn: () => controller.api.organizations(page),
    });
    return (
        <Screen
            headerless
            refresh={() => void query.refetch()}
            refreshing={query.isFetching}
        >
            <Heading>{t('studio')}</Heading>
            <ErrorMessage error={query.error || error} />
            {query.isFetching ? (
                <Loading />
            ) : query.data && !query.isError ? (
                <>
                    {query.data.data.length === 0 && <Body>{t('empty')}</Body>}
                    {query.data.data.map((org) => (
                        <Card key={org.id}>
                            <Body>{org.name}</Body>
                            <Body>{org.timezone}</Body>
                            <Button
                                label={`${t('open')}: ${org.name}`}
                                disabled={busy}
                                onPress={() => {
                                    setBusy(true);
                                    setError(null);
                                    void controller
                                        .select(org)
                                        .catch(setError)
                                        .finally(() => setBusy(false));
                                }}
                            />
                        </Card>
                    ))}
                    <Pager
                        page={page}
                        last={query.data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            ) : null}
            <Button
                label={t('logout')}
                onPress={() => void controller.logout().catch(setError)}
            />
        </Screen>
    );
}
