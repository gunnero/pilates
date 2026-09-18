# Locale, time and currency

Backend catalogs live in lang/mk and lang/en; Laravel defaults to mk with en fallback. Frontend uses i18next/react-i18next catalogs in resources/js/locales; locale switch updates the HTML lang and API Accept-Language header. Frontend defaults to mk on each load; preference persistence is deferred. UI copy is in catalogs, not distributed through components.

Application and database connections use UTC. Default operational timezone Europe/Skopje and currency MKD are centralized in config/studio.php and environment examples. Organization creation snapshots defaults into locale/timezone/currency columns, preparing per-organization settings without a management UI. Current shell displays organization identity, not invented dates or financial information.

Use Intl.DateTimeFormat / Intl.NumberFormat with the relevant locale, IANA timezone and currency when display is introduced. Store event instants in UTC, but store recurring local schedules with their original IANA timezone and explicit daylight-saving rules. Never assume Skopje has a fixed UTC offset. Organization local-date reporting must convert interval boundaries correctly. Do not infer timezone from the server clock or currency from UI language.

Macedonian UI, auth/validation errors, English alternatives and locale negotiation are covered by tests. Real notification content is deferred with external mail delivery; Laravel broker mechanics already exist.
