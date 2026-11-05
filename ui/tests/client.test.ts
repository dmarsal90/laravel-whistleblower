import { describe, expect, it, vi } from 'vitest'
import { ApiError, createClient } from '../src'

function mockFetch(status: number, payload: unknown) {
  return vi.fn(async (_url: RequestInfo | URL, _init?: RequestInit) =>
    new Response(payload === null ? 'not json' : JSON.stringify(payload), { status }))
}

const call = (f: ReturnType<typeof mockFetch>) => {
  const [url, init] = f.mock.calls[0]!
  return { url: String(url), init: init as RequestInit & { headers: Record<string, string> } }
}

describe('client', () => {
  it('sends the tracking code in the body, never in the URL', async () => {
    const f = mockFetch(200, { status: 'received', messages: [] })
    await createClient({ fetch: f }).followUp('ABCD-EFGH-JKMN-PQRS')

    const { url, init } = call(f)
    expect(url).toBe('/whistleblower/reports/status')
    expect(url).not.toContain('ABCD')
    expect(JSON.parse(init.body as string)).toEqual({ tracking_code: 'ABCD-EFGH-JKMN-PQRS' })
    expect(init).toMatchObject({ method: 'POST', cache: 'no-store', referrerPolicy: 'no-referrer', credentials: 'same-origin' })
  })

  it('honours baseUrl without a trailing slash and adds custom headers', async () => {
    const f = mockFetch(201, { tracking_code: 'X' })
    await createClient({ baseUrl: '/canal/', fetch: f, headers: () => ({ 'X-CSRF-TOKEN': 't' }) })
      .submit({ subject: 's', description: 'd' })

    const { url, init } = call(f)
    expect(url).toBe('/canal/reports')
    expect(init.headers).toMatchObject({ 'X-CSRF-TOKEN': 't', 'Content-Type': 'application/json' })
  })

  it('builds the list query from the filters only when set', async () => {
    const f = mockFetch(200, { data: [] })
    await createClient({ fetch: f }).manage.list({ status: 'received', overdue: true, page: 2 })
    expect(call(f).url).toBe('/whistleblower/manage/reports?status=received&overdue=1&page=2')

    const g = mockFetch(200, { data: [] })
    await createClient({ fetch: g }).manage.list()
    expect(call(g).url).toBe('/whistleblower/manage/reports')
  })

  it('escapes the report id and posts the action payloads', async () => {
    const f = mockFetch(200, {})
    await createClient({ fetch: f }).manage.resolve('a/b', 'done')

    const { url, init } = call(f)
    expect(url).toBe('/whistleblower/manage/reports/a%2Fb/resolve')
    expect(JSON.parse(init.body as string)).toEqual({ summary: 'done' })
  })

  it('maps 422 answers to an ApiError with field errors', async () => {
    const f = mockFetch(422, { message: 'invalid', errors: { subject: ['required'] } })
    const err = await createClient({ fetch: f }).submit({ subject: '', description: '' }).catch(e => e)

    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({ status: 422, message: 'invalid', errors: { subject: ['required'] } })
  })

  it.each([401, 403, 404, 409])('maps %i to an ApiError carrying the status', async (status) => {
    const err = await createClient({ fetch: mockFetch(status, { message: 'nope' }) }).manage.show('1').catch(e => e)
    expect(err).toMatchObject({ status, message: 'nope' })
  })

  it('survives a non-JSON error body and a network failure', async () => {
    const html = await createClient({ fetch: mockFetch(500, null) }).followUp('x').catch(e => e)
    expect(html).toMatchObject({ status: 500 })

    const down = vi.fn(async () => { throw new TypeError('offline') })
    const err = await createClient({ fetch: down }).followUp('x').catch(e => e)
    expect(err).toMatchObject({ status: 0 })
  })
})
