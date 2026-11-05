# Whistleblower UI

Vue 3 components for the [whistleblower](../README.md) API. They work with the Laravel and the Symfony
adapter, because both expose the same JSON API. No UI library and no state library: Vue and `fetch`.

Not published to npm yet. Build it from this repository (`cd ui && npm ci && npm run build`) and import
`dist/whistleblower-ui.js` and `dist/whistleblower-ui.css`.

## Use

```ts
import { createApp, h } from 'vue'
import { createClient, ReportForm } from '@dmarsal90/whistleblower-ui'
import '@dmarsal90/whistleblower-ui/style.css'

const client = createClient({ baseUrl: '/whistleblower' })

createApp({ render: () => h(ReportForm, { client, categories: ['Fraud', 'Harassment'], locale: 'en' }) }).mount('#app')
```

| Component | Props | Emits | Who sees it |
|---|---|---|---|
| `ReportForm` | `client`, `categories?`, `locale?` | `submitted(receipt)` | Anyone. Shows the tracking code once. |
| `FollowUp` | `client`, `locale?` | none | Anyone with a tracking code. |
| `ReportList` | `client`, `locale?` | `select(id)` | Channel managers. |
| `ReportDetail` | `client`, `reportId`, `locale?` | none | Channel managers. |

`locale` is `es` (default) or `en`. `ReportList` emits `select` instead of navigating, so it works with any router.
Every component renders an `h2`: the page around it provides the `h1`.

## Managers need a session and a CSRF token

The default `manage_middleware` is `['api', 'auth', ...]`, and the `api` group starts no session, so cookie
authentication cannot work with it. Either use token authentication (Sanctum tokens) or switch the group to `web`
in `config/whistleblower.php` and send the CSRF token from the client:

```php
'manage_middleware' => ['web', 'auth', 'can:whistleblower.manage'],
```

```ts
const xsrf = () => ({ 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') })
const client = createClient({ headers: xsrf })
```

Failed calls throw `ApiError` (`status`, `message`, and `errors` for a 422). Status 0 means the server was unreachable.

## Privacy

- The tracking code is only sent in request bodies, never in a URL, and requests use `no-store` and no referrer.
- It lives in memory only: it is not written to `localStorage` or cookies.
- Optional contact fields left empty are not sent, so the report stays anonymous.

## Styling

Override the CSS variables on `.wb` or an ancestor: `--wb-accent`, `--wb-accent-text`, `--wb-error`, `--wb-border`, `--wb-radius`.

## Development

```bash
npm ci
npm run typecheck && npm test && npm run build
```

Tested in jsdom, and by hand in Chromium against a Laravel 13 app (public flow, management flow with a session and
CSRF, 401 and 403 answers) with axe-core reporting no violations in the components.

## Known limits

- Dates use the browser's timezone and locale.
- The activity log shows the raw action codes (`viewed`, `acknowledged`...), untranslated.
- Opening a case records a view in the audit log, and so does every reload after an action.
- Field errors of a 422 come in the server's language.
