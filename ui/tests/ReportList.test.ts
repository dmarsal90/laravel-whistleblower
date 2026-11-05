import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, ReportList } from '../src'
import type { Page, ReportSummary } from '../src'

const row = (id: string, over: Partial<ReportSummary> = {}): ReportSummary => ({
  id, status: 'received', anonymous: true, received_at: '2026-01-01T00:00:00Z',
  acknowledgement_due_at: '2026-01-08T00:00:00Z', acknowledged_at: null, resolution_due_at: '2026-04-01T00:00:00Z',
  extended: false, anonymized: false, ...over,
})
const page = (data: ReportSummary[], total = data.length, p = 1): Page<ReportSummary> => ({ data, total, page: p, per_page: 25 })

async function setup(list = vi.fn(async (_f?: unknown) => page([row('01HXAAAAAAAAAAAAAAAAAAAA01'), row('01HXAAAAAAAAAAAAAAAAAAAA02', { anonymous: false, extended: true })]))) {
  const wrapper = mount(ReportList, { props: { client: { manage: { list } } } })
  await flushPromises()
  return { wrapper, list }
}

describe('ReportList', () => {
  it('loads the first page without filters and lists the rows', async () => {
    const { wrapper, list } = await setup()

    expect(list).toHaveBeenCalledWith({ status: undefined, overdue: undefined, page: 1 })
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('AAAAAA01')
    expect(wrapper.text()).toContain('Plazo vencido')
    expect(wrapper.text()).toContain('Plazo ampliado')
  })

  it('emits select with the full id', async () => {
    const { wrapper } = await setup()
    await wrapper.find('tbody button').trigger('click')

    expect(wrapper.emitted('select')?.[0]).toEqual(['01HXAAAAAAAAAAAAAAAAAAAA01'])
  })

  it('reloads with the chosen filters', async () => {
    const { wrapper, list } = await setup()
    await wrapper.find('select').setValue('investigating')
    await flushPromises()
    await wrapper.find('input[type="checkbox"]').setValue(true)
    await flushPromises()

    expect(list).toHaveBeenLastCalledWith({ status: 'investigating', overdue: true, page: 1 })
  })

  it('paginates and returns to page 1 when a filter changes', async () => {
    const list = vi.fn(async (f?: unknown) => page([row('01HXAAAAAAAAAAAAAAAAAAAA01')], 60, (f as { page: number }).page))
    const { wrapper } = await setup(list)

    expect(wrapper.find('[role="status"]').text()).toContain('Página 1 de 3')
    await wrapper.findAll('.wb-pager button')[1]!.trigger('click')
    await flushPromises()
    expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))

    await wrapper.find('select').setValue('received')
    await flushPromises()
    expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, status: 'received' }))
  })

  it('shows an empty state', async () => {
    const { wrapper } = await setup(vi.fn(async () => page([])))
    expect(wrapper.text()).toContain('No hay comunicaciones')
  })

  it.each([[401, 'Inicia sesión'], [403, 'No tienes permiso'], [500, 'No se pudo completar']])('answers %i with an alert', async (status, text) => {
    const { wrapper } = await setup(vi.fn(async () => { throw new ApiError(status, 'x') }))

    expect(wrapper.find('[role="alert"]').text()).toContain(text)
    expect(wrapper.find('table').exists()).toBe(false)
  })
})
