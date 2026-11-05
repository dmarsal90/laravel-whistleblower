import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, ReportForm } from '../src'

const receipt = { tracking_code: 'ABCD-EFGH-JKMN-PQRS', received_at: '2026-11-05T10:00:00+00:00', acknowledgement_due_at: '2026-11-12T10:00:00+00:00' }

function setup(submit = vi.fn(async () => receipt), props: Record<string, unknown> = {}) {
  const wrapper = mount(ReportForm, { props: { client: { submit }, ...props }, attachTo: document.body })
  return { wrapper, submit }
}

async function fill(wrapper: ReturnType<typeof setup>['wrapper']) {
  await wrapper.find('input[maxlength="200"]').setValue('  Fraude  ')
  await wrapper.find('textarea').setValue('Hechos')
}

describe('ReportForm', () => {
  it('sends only the filled fields, so an empty contact stays anonymous', async () => {
    const { wrapper, submit } = setup()
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')

    expect(submit).toHaveBeenCalledWith({ subject: 'Fraude', description: 'Hechos' })
  })

  it('sends the category and contact details when given', async () => {
    const { wrapper, submit } = setup(undefined, { categories: ['Fraude', 'Acoso'] })
    await fill(wrapper)
    await wrapper.find('select').setValue('Acoso')
    await wrapper.find('input[type="email"]').setValue('a@b.es')
    await wrapper.find('form').trigger('submit')

    expect(submit).toHaveBeenCalledWith({ subject: 'Fraude', description: 'Hechos', category: 'Acoso', contact_email: 'a@b.es' })
  })

  it('hides the category field when none are configured', () => {
    expect(setup().wrapper.find('select').exists()).toBe(false)
  })

  it('shows the tracking code once, warns about it, emits and clears the form', async () => {
    const { wrapper } = setup()
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('.wb-code').text()).toBe(receipt.tracking_code)
    expect(wrapper.find('.wb-warning').exists()).toBe(true)
    expect(wrapper.emitted('submitted')?.[0]).toEqual([receipt])
    expect(document.activeElement).toBe(wrapper.find('h2').element)
  })

  it('copies the code and announces it', async () => {
    const writeText = vi.fn(async () => {})
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const { wrapper } = setup()
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(receipt.tracking_code)
    expect(wrapper.find('[role="status"]').text()).toBe('Código copiado')
  })

  it('links 422 field errors to their inputs', async () => {
    const { wrapper } = setup(vi.fn(async () => { throw new ApiError(422, 'invalid', { subject: ['Too long'] }) }))
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const input = wrapper.find('input[maxlength="200"]')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.find(`#${input.attributes('aria-describedby')}`).text()).toBe('Too long')
    expect(wrapper.find('.wb-code').exists()).toBe(false)
  })

  it.each([[429, 'Demasiados intentos'], [500, 'No se pudo enviar']])('shows an alert for %i and keeps the form', async (status, text) => {
    const { wrapper } = setup(vi.fn(async () => { throw new ApiError(status, 'x') }))
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain(text)
    expect(wrapper.find('textarea').element.value).toBe('Hechos')
  })

  it('switches language', () => {
    expect(setup(undefined, { locale: 'en' }).wrapper.find('h2').text()).toBe('Report a breach')
  })
})
