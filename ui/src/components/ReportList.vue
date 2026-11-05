<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue'
import type { Client } from '../client'
import { translator } from '../i18n'
import type { Locale } from '../i18n'
import { isOverdue } from '../lifecycle'
import { manageError } from '../manageError'
import type { Page, ReportStatus, ReportSummary } from '../types'

const props = withDefaults(defineProps<{
  client: { manage: Pick<Client['manage'], 'list'> }
  locale?: Locale
}>(), { locale: 'es' })

const emit = defineEmits<{ select: [id: string] }>()

const tr = computed(() => translator(props.locale))
const t = (...a: Parameters<typeof tr.value.t>) => tr.value.t(...a)
const id = useId()

const statuses: ReportStatus[] = ['received', 'acknowledged', 'investigating', 'resolved', 'archived']
const status = ref<ReportStatus | ''>('')
const overdueOnly = ref(false)
const page = ref(1)
const result = ref<Page<ReportSummary> | null>(null)
const busy = ref(false)
const failure = ref('')

const pages = computed(() => (result.value ? Math.max(1, Math.ceil(result.value.total / result.value.per_page)) : 1))

async function load() {
  busy.value = true
  failure.value = ''
  try {
    result.value = await props.client.manage.list({
      status: status.value || undefined,
      overdue: overdueOnly.value || undefined,
      page: page.value,
    })
  } catch (e) {
    result.value = null
    failure.value = manageError(e, t)
  } finally {
    busy.value = false
  }
}

// A new filter starts again from the first page.
watch([status, overdueOnly], () => {
  if (page.value === 1) load()
  else page.value = 1
})
watch(page, load)
onMounted(load)
</script>

<template>
  <section class="wb wb-wide">
    <h2>{{ t('list.title') }}</h2>

    <div class="wb-filters">
      <div class="wb-field">
        <label :for="`${id}-status`">{{ t('list.filterStatus') }}</label>
        <select :id="`${id}-status`" v-model="status">
          <option value="">{{ t('list.allStatuses') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ tr.status(s) }}</option>
        </select>
      </div>
      <label class="wb-check"><input v-model="overdueOnly" type="checkbox"> {{ t('list.overdueOnly') }}</label>
    </div>

    <p v-if="failure" class="wb-error" role="alert">{{ failure }}</p>
    <p v-else-if="busy && !result" role="status">{{ t('manage.loading') }}</p>

    <template v-if="result">
      <p v-if="!result.data.length">{{ t('list.empty') }}</p>
      <div v-else class="wb-table-wrap" :aria-busy="busy">
        <table class="wb-table">
          <caption class="wb-sr-only">{{ t('list.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('list.colId') }}</th>
              <th scope="col">{{ t('list.colStatus') }}</th>
              <th scope="col">{{ t('list.colReceived') }}</th>
              <th scope="col">{{ t('list.colAckDue') }}</th>
              <th scope="col">{{ t('list.colResolutionDue') }}</th>
              <th scope="col">{{ t('list.colFlags') }}</th>
              <th scope="col"><span class="wb-sr-only">{{ t('list.open') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in result.data" :key="r.id" :class="{ 'wb-overdue': isOverdue(r) }">
              <th scope="row"><code>{{ r.id.slice(-8) }}</code></th>
              <td>{{ tr.status(r.status) }}</td>
              <td>{{ tr.date(r.received_at) }}</td>
              <td>{{ r.acknowledged_at ? '—' : tr.date(r.acknowledgement_due_at) }}</td>
              <td>{{ r.resolution_due_at ? tr.date(r.resolution_due_at) : '—' }}</td>
              <td>
                <span v-if="isOverdue(r)" class="wb-badge wb-badge-alert">{{ t('list.overdue') }}</span>
                <span v-if="r.anonymous" class="wb-badge">{{ t('list.anonymous') }}</span>
                <span v-if="r.extended" class="wb-badge">{{ t('list.extended') }}</span>
                <span v-if="r.anonymized" class="wb-badge">{{ t('list.anonymized') }}</span>
              </td>
              <td>
                <button type="button" class="wb-button wb-button-small" @click="emit('select', r.id)">
                  {{ t('list.open') }}<span class="wb-sr-only"> {{ r.id.slice(-8) }}</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav v-if="pages > 1" class="wb-pager" :aria-label="t('list.title')">
        <button type="button" class="wb-button wb-button-small" :disabled="page <= 1 || busy" @click="page--">{{ t('list.prev') }}</button>
        <span role="status">{{ t('list.pageOf', { page: String(page), pages: String(pages), total: String(result.total) }) }}</span>
        <button type="button" class="wb-button wb-button-small" :disabled="page >= pages || busy" @click="page++">{{ t('list.next') }}</button>
      </nav>
    </template>
  </section>
</template>
