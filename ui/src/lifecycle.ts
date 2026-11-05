import type { ReportStatus, ReportSummary } from './types'

export type Action = 'acknowledge' | 'investigate' | 'extend' | 'resolve' | 'archive'

/** Mirrors the transitions of Whistleblower\Whistleblower: the server stays the authority and answers 409 otherwise. */
export function availableActions(status: ReportStatus, extended: boolean): Action[] {
  const actions: Action[] = []
  if (status === 'received') actions.push('acknowledge')
  if (status === 'received' || status === 'acknowledged') actions.push('investigate')
  if (!extended && ['received', 'acknowledged', 'investigating'].includes(status)) actions.push('extend')
  if (status === 'investigating') actions.push('resolve')
  if (status !== 'archived') actions.push('archive')

  return actions
}

/** A visual hint only: the open report is past its acknowledgement or resolution deadline. */
export function isOverdue(r: ReportSummary, now: Date = new Date()): boolean {
  if (r.status === 'resolved' || r.status === 'archived') return false
  const ackLate = r.acknowledged_at === null && new Date(r.acknowledgement_due_at) < now
  const resolutionLate = r.resolution_due_at !== null && new Date(r.resolution_due_at) < now

  return ackLate || resolutionLate
}
