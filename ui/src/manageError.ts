import { ApiError } from './client'
import type { Key } from './i18n'

/** The message for a failed management call. A 409 carries a readable reason from the server. */
export function manageError(e: unknown, t: (key: Key) => string): string {
  if (e instanceof ApiError) {
    if (e.status === 401) return t('manage.unauthenticated')
    if (e.status === 403) return t('manage.forbidden')
    if (e.status === 404) return t('manage.notFound')
    if (e.status === 409 && e.message) return e.message
  }

  return t('manage.error')
}
