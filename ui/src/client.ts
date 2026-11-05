import type {
  FollowUp, ListFilters, Page, Receipt, ReportDetail, ReportSummary, SubmitInput,
} from './types'

export interface ClientOptions {
  /** Where the host mounted the routes. Default: /whistleblower */
  baseUrl?: string
  /** Extra headers per request, e.g. an X-CSRF-TOKEN or X-XSRF-TOKEN for the management routes. */
  headers?: () => Record<string, string>
  /** Use 'include' only if the API lives on another origin. Default: same-origin */
  credentials?: RequestCredentials
  fetch?: typeof fetch
}

/** A failed request. status 0 means the server could not be reached. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    /** Field errors of a 422 answer. */
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

export function createClient(options: ClientOptions = {}) {
  const baseUrl = (options.baseUrl ?? '/whistleblower').replace(/\/+$/, '')
  const doFetch = options.fetch ?? ((input, init) => fetch(input, init))

  async function request<T>(method: 'GET' | 'POST', path: string, body?: unknown, query?: Record<string, string>): Promise<T> {
    const qs = query && Object.keys(query).length > 0 ? `?${new URLSearchParams(query)}` : ''
    let response: Response
    try {
      response = await doFetch(`${baseUrl}/${path}${qs}`, {
        method,
        headers: {
          Accept: 'application/json',
          ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
          ...options.headers?.(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: options.credentials ?? 'same-origin',
        cache: 'no-store',
        referrerPolicy: 'no-referrer',
      })
    } catch {
      throw new ApiError(0, 'Network error')
    }

    const payload: unknown = await response.json().catch(() => null)
    if (!response.ok) {
      const p = (payload ?? {}) as { message?: unknown, errors?: Record<string, string[]> }
      throw new ApiError(response.status, typeof p.message === 'string' ? p.message : response.statusText, p.errors)
    }

    return payload as T
  }

  const manage = (id: string, action: string, body?: unknown) =>
    request<ReportSummary>('POST', `manage/reports/${encodeURIComponent(id)}/${action}`, body ?? {})

  return {
    /** The tracking code goes in the body, never in the URL. */
    submit: (input: SubmitInput) => request<Receipt>('POST', 'reports', input),
    followUp: (trackingCode: string) => request<FollowUp>('POST', 'reports/status', { tracking_code: trackingCode }),
    reply: (trackingCode: string, body: string) =>
      request<{ status: string }>('POST', 'reports/messages', { tracking_code: trackingCode, body }),

    manage: {
      list(filters: ListFilters = {}) {
        const query: Record<string, string> = {}
        if (filters.status) query.status = filters.status
        if (filters.overdue) query.overdue = '1'
        if (filters.page) query.page = String(filters.page)

        return request<Page<ReportSummary>>('GET', 'manage/reports', undefined, query)
      },
      show: (id: string) => request<ReportDetail>('GET', `manage/reports/${encodeURIComponent(id)}`),
      acknowledge: (id: string) => manage(id, 'acknowledge'),
      investigate: (id: string) => manage(id, 'investigate'),
      extend: (id: string, justification: string) => manage(id, 'extend', { justification }),
      resolve: (id: string, summary: string) => manage(id, 'resolve', { summary }),
      archive: (id: string) => manage(id, 'archive'),
      message: (id: string, body: string, internal = false) =>
        request<{ status: string }>('POST', `manage/reports/${encodeURIComponent(id)}/messages`, { body, internal }),
    },
  }
}

export type Client = ReturnType<typeof createClient>
