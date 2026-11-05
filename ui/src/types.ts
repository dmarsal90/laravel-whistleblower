/** The JSON shapes of the API. They mirror Whistleblower\Presenter on the PHP side. */

export type ReportStatus = 'received' | 'acknowledged' | 'investigating' | 'resolved' | 'archived'

export interface SubmitInput {
  subject: string
  description: string
  category?: string
  contact_name?: string
  contact_email?: string
}

/** Returned once on submit: the tracking code cannot be recovered afterwards. */
export interface Receipt {
  tracking_code: string
  received_at: string
  acknowledgement_due_at: string
}

export interface ReporterMessage {
  author: 'reporter' | 'manager'
  body: string
  created_at: string
}

/** What the reporter sees when following up with the tracking code. */
export interface FollowUpData {
  status: ReportStatus
  received_at: string
  acknowledged_at: string | null
  resolution_due_at: string | null
  resolution: string | null
  messages: ReporterMessage[]
}

export interface ReportSummary {
  id: string
  status: ReportStatus
  anonymous: boolean
  received_at: string
  acknowledgement_due_at: string
  acknowledged_at: string | null
  resolution_due_at: string | null
  extended: boolean
  anonymized: boolean
}

export interface ManagerMessage extends ReporterMessage {
  user_id: string | number | null
}

export interface AuditEntry {
  actor_type: string
  actor_id: string | number | null
  action: string
  created_at: string
}

export interface ReportDetail extends ReportSummary {
  subject: string | null
  description: string | null
  reporter_name: string | null
  reporter_email: string | null
  resolution: string | null
  messages: ManagerMessage[]
  audit: AuditEntry[]
}

export interface Page<T> {
  data: T[]
  total: number
  page: number
  per_page: number
}

export interface ListFilters {
  status?: ReportStatus
  overdue?: boolean
  page?: number
}
