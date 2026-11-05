import { describe, expect, it } from 'vitest'
import { availableActions, isOverdue } from '../src/lifecycle'
import type { ReportSummary } from '../src'

const base: ReportSummary = {
  id: 'r1', status: 'received', anonymous: true, received_at: '2026-01-01T00:00:00Z',
  acknowledgement_due_at: '2026-01-08T00:00:00Z', acknowledged_at: null, resolution_due_at: '2026-04-01T00:00:00Z',
  extended: false, anonymized: false,
}

describe('availableActions', () => {
  it.each([
    ['received', false, ['acknowledge', 'investigate', 'extend', 'archive']],
    ['acknowledged', false, ['investigate', 'extend', 'archive']],
    ['investigating', false, ['extend', 'resolve', 'archive']],
    ['investigating', true, ['resolve', 'archive']],
    ['resolved', false, ['archive']],
    ['archived', false, []],
  ] as const)('%s (extended: %s)', (status, extended, expected) => {
    expect(availableActions(status, extended)).toEqual(expected)
  })
})

describe('isOverdue', () => {
  it('flags an unacknowledged report past its acknowledgement deadline', () => {
    expect(isOverdue(base, new Date('2026-01-09T00:00:00Z'))).toBe(true)
    expect(isOverdue(base, new Date('2026-01-07T00:00:00Z'))).toBe(false)
  })

  it('stops caring about the acknowledgement once acknowledged, but not about the resolution', () => {
    const acked = { ...base, status: 'acknowledged' as const, acknowledged_at: '2026-01-02T00:00:00Z' }
    expect(isOverdue(acked, new Date('2026-02-01T00:00:00Z'))).toBe(false)
    expect(isOverdue(acked, new Date('2026-04-02T00:00:00Z'))).toBe(true)
  })

  it('never flags closed reports', () => {
    const now = new Date('2030-01-01T00:00:00Z')
    expect(isOverdue({ ...base, status: 'resolved' }, now)).toBe(false)
    expect(isOverdue({ ...base, status: 'archived' }, now)).toBe(false)
  })
})
