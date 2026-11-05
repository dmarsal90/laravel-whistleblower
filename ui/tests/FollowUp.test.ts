import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, FollowUp } from '../src'
import type { FollowUpData } from '../src'

const data: FollowUpData = {
  status: 'acknowledged',
  received_at: '2026-11-05T10:00:00+00:00',
  acknowledged_at: '2026-11-06T10:00:00+00:00',
  resolution_due_at: '2027-02-05T10:00:00+00:00',
  resolution: null,
  messages: [{ author: 'manager', body: 'Necesitamos más datos', created_at: '2026-11-07T10:00:00+00:00' }],
}

function setup(followUp = vi.fn(async (_code: string) => data), reply = vi.fn(async (_code: string, _body: string) => ({ status: 'acknowledged' }))) {
  const wrapper = mount(FollowUp, { props: { client: { followUp, reply } } })
  return { wrapper, followUp, reply }
}

async function lookup(wrapper: ReturnType<typeof setup>['wrapper'], code = ' abcd-efgh ') {
  await wrapper.find('input').setValue(code)
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('FollowUp', () => {
  it('looks the code up (trimmed) and shows status, dates and messages', async () => {
    const { wrapper, followUp } = setup()
    await lookup(wrapper)

    expect(followUp).toHaveBeenCalledWith('abcd-efgh')
    expect(wrapper.text()).toContain('Estado: Recibo acusado')
    expect(wrapper.text()).toContain('Plazo de respuesta')
    expect(wrapper.text()).toContain('Responsable del canal')
    expect(wrapper.text()).toContain('Necesitamos más datos')
  })

  it('shows the resolution when there is one', async () => {
    const { wrapper } = setup(vi.fn(async () => ({ ...data, status: 'resolved' as const, resolution: 'Caso cerrado' })))
    await lookup(wrapper)

    expect(wrapper.text()).toContain('Caso cerrado')
  })

  it.each([[404, 'No encontramos'], [429, 'Demasiados intentos'], [500, 'No se pudo consultar']])('answers %i with an alert and no data', async (status, text) => {
    const { wrapper } = setup(vi.fn(async () => { throw new ApiError(status, 'x') }))
    await lookup(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain(text)
    expect(wrapper.find('.wb-result').exists()).toBe(false)
  })

  it('replies with the looked-up code, then refreshes', async () => {
    const { wrapper, followUp, reply } = setup()
    await lookup(wrapper)
    await wrapper.find('textarea').setValue('  Adjunto detalles ')
    await wrapper.findAll('form')[1]!.trigger('submit')
    await flushPromises()

    expect(reply).toHaveBeenCalledWith('abcd-efgh', 'Adjunto detalles')
    expect(followUp).toHaveBeenCalledTimes(2)
    expect(wrapper.find('textarea').element.value).toBe('')
  })

  it('uses the code that was looked up, not what was typed since', async () => {
    const { wrapper, reply } = setup()
    await lookup(wrapper)
    await wrapper.find('input').setValue('otro-codigo')
    await wrapper.find('textarea').setValue('hola')
    await wrapper.findAll('form')[1]!.trigger('submit')
    await flushPromises()

    expect(reply).toHaveBeenCalledWith('abcd-efgh', 'hola')
  })

  it('shows the server message when the report no longer accepts replies (409)', async () => {
    const { wrapper } = setup(undefined, vi.fn(async () => { throw new ApiError(409, 'Report is closed') }))
    await lookup(wrapper)
    await wrapper.find('textarea').setValue('hola')
    await wrapper.findAll('form')[1]!.trigger('submit')
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]').at(-1)!.text()).toBe('Report is closed')
  })
})
