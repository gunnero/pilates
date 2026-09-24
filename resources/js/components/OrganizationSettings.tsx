import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { StudioPolicies } from './StudioPolicies';
import { api, errorMessage, type Organization } from '../lib/api';
export function OrganizationSettings({
    organization,
    onChanged,
}: {
    organization: Organization;
    onChanged: () => void;
}) {
    const { t } = useTranslation();
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const manage = organization.permissions.includes('organizations.manage');
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget));
        if (
            data.timezone !== organization.timezone &&
            !window.confirm(t('policy.timezoneConfirm'))
        )
            return;
        setBusy(true);
        setError('');
        setSaved(false);
        try {
            await api.patch(`/api/v1/organizations/${organization.id}`, data);
            setSaved(true);
            onChanged();
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }
    if (!organization.permissions.includes('organizations.view'))
        return <p role="alert">{t('accessDenied')}</p>;
    return (
        <section className="management-panel">
            <h2>{t('organizationSettings')}</h2>
            <h3>{t('policy.general')}</h3>
            <form
                className="management-form"
                onSubmit={(event) => void submit(event)}
            >
                <fieldset disabled={!manage || busy}>
                    <label>
                        {t('studioName')}
                        <input
                            name="name"
                            defaultValue={organization.name}
                            required
                            maxLength={160}
                        />
                    </label>
                    <label>
                        {t('language')}
                        <select
                            name="locale"
                            defaultValue={organization.locale}
                        >
                            <option value="mk">Македонски</option>
                            <option value="en">English</option>
                        </select>
                    </label>
                    <label>
                        {t('timezone')}
                        <select
                            name="timezone"
                            defaultValue={organization.timezone}
                        >
                            {[
                                ...new Set([
                                    organization.timezone,
                                    'UTC',
                                    ...Intl.supportedValuesOf('timeZone'),
                                ]),
                            ]
                                .sort()
                                .map((zone) => (
                                    <option key={zone}>{zone}</option>
                                ))}
                        </select>
                        <small>{t('policy.timezoneHelp')}</small>
                    </label>
                    <label>
                        {t('currency')}
                        <select
                            name="currency"
                            defaultValue={organization.currency}
                        >
                            <option value="MKD">MKD</option>
                        </select>
                    </label>
                    <label>
                        {t('status')}
                        <select
                            name="status"
                            defaultValue={organization.status}
                        >
                            <option value="active">{t('active')}</option>
                            <option value="inactive">{t('inactive')}</option>
                        </select>
                    </label>
                    <p className="muted">{t('deactivationWarning')}</p>
                    {manage && (
                        <button className="primary" disabled={busy}>
                            {t(busy ? 'working' : 'save')}
                        </button>
                    )}
                </fieldset>
                {error && (
                    <p className="error" role="alert">
                        {error}
                    </p>
                )}
                {saved && <p role="status">{t('saved')}</p>}
            </form>
            <StudioPolicies
                key={organization.id}
                organization={organization.id}
                manage={manage}
            />
        </section>
    );
}
