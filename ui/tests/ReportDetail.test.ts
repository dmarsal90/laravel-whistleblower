import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, ReportDetail } from '../src'
import type { ReportDetailData as Detail } from '../src'

const detail = (over: Partial<Detail> = {}): Detail => ({
  id: '01HXAAAAAAAAAAAAAAAAAAAA01', status: 'received', anonymous: false, received_at: '2026-01-01T00:00:00Z',
  acknowledgement_due_at: '2026-01-08T00:00:00Z', acknowledged_at: null, resolution_due_at: '2026-04-01T00:00:00Z',
  extended: false, anonymized: false, subject: 'Fraude', description: 'Hechos', reporter_name: 'Ana', reporter_email: 'a@b.es',
  resolution: null,
  messages: [
    { author: 'reporter', user_id: null, body: 'Hola', created_at: '2026-01-02T00:00:00Z' },
    { author: 'internal', user_id: 3, body: 'Nota privada', created_at: '2026-01-03T00:00:00Z' },
  ],
  audit: [{ actor_type: 'user', actor_id: 3, action: 'viewed', created_at: '2026-01-03T00:00:00Z' }],
  ...over,
})

function setup(initial: Detail = detail(), overrides: Record<string, unknown> = {}) {
  const manage = {
    show: vi.fn(async (_id: string) => initial),
    acknowledge: vi.fn(async () => ({})), investigate: vi.fn(async () => ({})), archive: vi.fn(async () => ({})),
    extend: vi.fn(async (_id: string, _j: string) => ({})), resolve: vi.fn(async (_id: string, _s: string) => ({})),
    message: vi.fn(async (_id: string, _b: string, _i?: boolean) => ({ status: 'sent' })),
    ...overrides,
  }
  const wrapper = mount(ReportDetail, { props: { client: { manage: manage as never }, reportId: initial.id } })
  return { wrapper, manage }
}

const buttonByText = (w: ReturnType<typeof setup>['wrapper'], text: string) => w.findAll('button').find(b => b.text() === text)

describe('ReportDetail', () => {
  it('shows the case, the reporter contact and the internal notes marked as such', async () => {
    const { wrapper } = setup()
    await flushPromises()

    expect(wrapper.text()).toContain('Fraude')
    expect(wrapper.text()).toContain('Ana · a@b.es')
    expect(wrapper.find('.wb-internal').text()).toContain('Nota interna')
    expect(wrapper.find('.wb-audit').text()).toContain('viewed')
  })

  it('keeps the acknowledgement deadline and shows when it was acknowledged', async () => {
    const { wrapper } = setup(detail({ status: 'acknowledged', acknowledgement_due_at: '2026-01-08T12:00:00Z', acknowledged_at: '2026-01-03T12:00:00Z' }))
    await flushPromises()
    const facts = wrapper.find('.wb-facts').text()

    expect(facts).toContain('Acuse antes de')
    expect(facts).toContain('8 de enero de 2026')
    expect(facts).toContain('Recibo acusado el')
    expect(facts).toContain('3 de enero de 2026')
  })

  it('says anonymous when there is no contact', async () => {
    const { wrapper } = setup(detail({ reporter_name: null, reporter_email: null }))
    await flushPromises()

    expect(wrapper.find('.wb-facts').text()).toContain('Anónimo')
  })

  it('offers only the actions valid for the status', async () => {
    const { wrapper } = setup(detail({ status: 'investigating' }))
    await flushPromises()

    expect(buttonByText(wrapper, 'Acusar recibo')).toBeUndefined()
    expect(buttonByText(wrapper, 'Resolver')).toBeDefined()
    expect(wrapper.find('form textarea').exists()).toBe(true)
  })

  it('acknowledges and reloads the case', async () => {
    const { wrapper, manage } = setup()
    await flushPromises()
    await buttonByText(wrapper, 'Acusar recibo')!.trigger('click')
    await flushPromises()

    expect(manage.acknowledge).toHaveBeenCalledWith('01HXAAAAAAAAAAAAAAAAAAAA01')
    expect(manage.show).toHaveBeenCalledTimes(2)
  })

  it('asks for confirmation before archiving', async () => {
    const { wrapper, manage } = setup()
    await flushPromises()
    await buttonByText(wrapper, 'Archivar')!.trigger('click')
    expect(manage.archive).not.toHaveBeenCalled()

    await buttonByText(wrapper, 'Cancelar')!.trigger('click')
    expect(buttonByText(wrapper, 'Archivar')).toBeDefined()

    await buttonByText(wrapper, 'Archivar')!.trigger('click')
    await buttonByText(wrapper, 'Confirmar archivo')!.trigger('click')
    await flushPromises()
    expect(manage.archive).toHaveBeenCalledOnce()
  })

  it('sends the trimmed justification and clears it', async () => {
    const { wrapper, manage } = setup()
    await flushPromises()
    const form = wrapper.findAll('form')[0]!
    await form.find('textarea').setValue('  Caso complejo ')
    await form.trigger('submit')
    await flushPromises()

    expect(manage.extend).toHaveBeenCalledWith('01HXAAAAAAAAAAAAAAAAAAAA01', 'Caso complejo')
  })

  it('hides the extension once the deadline was extended', async () => {
    const { wrapper } = setup(detail({ extended: true }))
    await flushPromises()

    expect(buttonByText(wrapper, 'Ampliar plazo')).toBeUndefined()
  })

  it('sends a message, internal when ticked', async () => {
    const { wrapper, manage } = setup(detail({ status: 'acknowledged' }))
    await flushPromises()
    const form = wrapper.findAll('form').at(-1)!
    await form.find('textarea').setValue('Revisar')
    await form.find('input[type="checkbox"]').setValue(true)
    await form.trigger('submit')
    await flushPromises()

    expect(manage.message).toHaveBeenCalledWith('01HXAAAAAAAAAAAAAAAAAAAA01', 'Revisar', true)
  })

  it('does not allow messages on archived or anonymised reports', async () => {
    const archived = setup(detail({ status: 'archived' }))
    await flushPromises()
    expect(archived.wrapper.find('form').exists()).toBe(false)

    const anon = setup(detail({ anonymized: true }))
    await flushPromises()
    expect(anon.wrapper.text()).toContain('anonimizado')
    expect(anon.wrapper.findAll('form').some(f => f.find('input[type="checkbox"]').exists())).toBe(false)
  })

  it('keeps what was typed and shows the server reason when a change is refused (409)', async () => {
    const { wrapper } = setup(detail({ status: 'investigating' }), {
      resolve: vi.fn(async () => { throw new ApiError(409, 'Cannot resolve in this state') }),
    })
    await flushPromises()
    const form = wrapper.findAll('form').find(f => f.text().includes('Resolver'))!
    await form.find('textarea').setValue('Resumen')
    await form.trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toBe('Cannot resolve in this state')
    expect(form.find('textarea').element.value).toBe('Resumen')
  })

  it.each([[404, 'No se encontró'], [403, 'No tienes permiso']])('answers %i when loading', async (status, text) => {
    const { wrapper } = setup(detail(), { show: vi.fn(async () => { throw new ApiError(status, 'x') }) })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain(text)
  })
})
