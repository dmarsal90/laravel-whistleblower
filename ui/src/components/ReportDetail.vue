<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue'
import type { Client } from '../client'
import { translator } from '../i18n'
import type { Locale } from '../i18n'
import { availableActions } from '../lifecycle'
import { manageError } from '../manageError'
import type { ReportDetailData } from '../types'

const props = withDefaults(defineProps<{
  client: { manage: Pick<Client['manage'], 'show' | 'acknowledge' | 'investigate' | 'extend' | 'resolve' | 'archive' | 'message'> }
  /** The id of the report, as given by the list. */
  reportId: string
  locale?: Locale
}>(), { locale: 'es' })

const tr = computed(() => translator(props.locale))
const t = (...a: Parameters<typeof tr.value.t>) => tr.value.t(...a)
const id = useId()

const report = ref<ReportDetailData | null>(null)
const loadFailure = ref('')
const failure = ref('')
const busy = ref(false)

const justification = ref('')
const summary = ref('')
const note = ref('')
const internal = ref(false)
const confirmingArchive = ref(false)

const actions = computed(() => (report.value ? availableActions(report.value.status, report.value.extended) : []))
const canMessage = computed(() => report.value !== null && report.value.status !== 'archived' && !report.value.anonymized)

async function load() {
  loadFailure.value = ''
  try {
    report.value = await props.client.manage.show(props.reportId)
  } catch (e) {
    report.value = null
    loadFailure.value = manageError(e, t)
  }
}

/** Runs a change, then reloads the case. A refused change (409) keeps the form so nothing typed is lost. */
async function run(change: () => Promise<unknown>, onDone: () => void) {
  busy.value = true
  failure.value = ''
  try {
    await change()
    onDone()
    confirmingArchive.value = false
    await load()
  } catch (e) {
    failure.value = manageError(e, t)
  } finally {
    busy.value = false
  }
}

const acknowledge = () => run(() => props.client.manage.acknowledge(props.reportId), () => {})
const investigate = () => run(() => props.client.manage.investigate(props.reportId), () => {})
const archive = () => run(() => props.client.manage.archive(props.reportId), () => {})
const extend = () => run(() => props.client.manage.extend(props.reportId, justification.value.trim()), () => { justification.value = '' })
const resolve = () => run(() => props.client.manage.resolve(props.reportId, summary.value.trim()), () => { summary.value = '' })
const sendMessage = () => run(
  () => props.client.manage.message(props.reportId, note.value.trim(), internal.value),
  () => { note.value = ''; internal.value = false },
)

watch(() => props.reportId, load)
onMounted(load)
</script>

<template>
  <section class="wb wb-wide">
    <p v-if="loadFailure" class="wb-error" role="alert">{{ loadFailure }}</p>
    <p v-else-if="!report" role="status">{{ t('manage.loading') }}</p>

    <template v-else>
      <h2>{{ report.subject ?? report.id.slice(-8) }}</h2>
      <p>{{ tr.status(report.status) }} · {{ tr.date(report.received_at) }}</p>
      <p v-if="report.anonymized" class="wb-warning">{{ t('detail.anonymizedNotice') }}</p>

      <dl class="wb-facts">
        <dt>{{ t('list.colAckDue') }}</dt>
        <dd>{{ tr.date(report.acknowledgement_due_at) }}</dd>
        <template v-if="report.acknowledged_at">
          <dt>{{ t('detail.acknowledgedOn') }}</dt>
          <dd>{{ tr.date(report.acknowledged_at) }}</dd>
        </template>
        <template v-if="report.resolution_due_at">
          <dt>{{ t('list.colResolutionDue') }}</dt>
          <dd>{{ tr.date(report.resolution_due_at) }}</dd>
        </template>
        <dt>{{ t('detail.reporter') }}</dt>
        <dd>
          <template v-if="report.reporter_name || report.reporter_email">{{ [report.reporter_name, report.reporter_email].filter(Boolean).join(' · ') }}</template>
          <template v-else>{{ t('detail.anonymousReporter') }}</template>
        </dd>
      </dl>

      <template v-if="report.description">
        <h3>{{ t('form.description') }}</h3>
        <p class="wb-text">{{ report.description }}</p>
      </template>
      <template v-if="report.resolution">
        <h3>{{ t('detail.resolution') }}</h3>
        <p class="wb-text">{{ report.resolution }}</p>
      </template>

      <h3>{{ t('detail.messages') }}</h3>
      <p v-if="!report.messages.length">{{ t('detail.noMessages') }}</p>
      <ul v-else class="wb-messages">
        <li v-for="(m, i) in report.messages" :key="i" :class="{ 'wb-internal': m.author === 'internal' }">
          <strong>{{ t(`author.${m.author}`) }}</strong>
          <time :datetime="m.created_at">{{ tr.date(m.created_at) }}</time>
          <p class="wb-text">{{ m.body }}</p>
        </li>
      </ul>

      <h3>{{ t('detail.actions') }}</h3>
      <p v-if="failure" class="wb-error" role="alert">{{ failure }}</p>

      <div class="wb-actions">
        <button v-if="actions.includes('acknowledge')" type="button" class="wb-button" :disabled="busy" @click="acknowledge">{{ t('action.acknowledge') }}</button>
        <button v-if="actions.includes('investigate')" type="button" class="wb-button" :disabled="busy" @click="investigate">{{ t('action.investigate') }}</button>
        <template v-if="actions.includes('archive')">
          <button v-if="!confirmingArchive" type="button" class="wb-button wb-button-secondary" :disabled="busy" @click="confirmingArchive = true">{{ t('action.archive') }}</button>
          <template v-else>
            <button type="button" class="wb-button wb-button-danger" :disabled="busy" @click="archive">{{ t('action.confirmArchive') }}</button>
            <button type="button" class="wb-button wb-button-secondary" @click="confirmingArchive = false">{{ t('action.cancel') }}</button>
          </template>
        </template>
      </div>

      <form v-if="actions.includes('extend')" @submit.prevent="extend">
        <div class="wb-field">
          <label :for="`${id}-extend`">{{ t('action.justification') }}</label>
          <textarea :id="`${id}-extend`" v-model="justification" required rows="3" maxlength="5000" />
        </div>
        <button type="submit" class="wb-button" :disabled="busy">{{ t('action.extend') }}</button>
      </form>

      <form v-if="actions.includes('resolve')" @submit.prevent="resolve">
        <div class="wb-field">
          <label :for="`${id}-resolve`">{{ t('action.summary') }}</label>
          <textarea :id="`${id}-resolve`" v-model="summary" required rows="4" maxlength="10000" />
        </div>
        <button type="submit" class="wb-button" :disabled="busy">{{ t('action.resolve') }}</button>
      </form>

      <form v-if="canMessage" @submit.prevent="sendMessage">
        <div class="wb-field">
          <label :for="`${id}-message`">{{ t('action.message') }}</label>
          <textarea :id="`${id}-message`" v-model="note" required rows="3" maxlength="5000" />
        </div>
        <label class="wb-check"><input v-model="internal" type="checkbox"> {{ t('action.internal') }}</label>
        <button type="submit" class="wb-button" :disabled="busy">{{ t('action.send') }}</button>
      </form>

      <h3>{{ t('detail.audit') }}</h3>
      <ul class="wb-audit">
        <li v-for="(a, i) in report.audit" :key="i">
          <time :datetime="a.created_at">{{ tr.date(a.created_at) }}</time> · {{ a.actor_type }}{{ a.actor_id !== null ? ` #${a.actor_id}` : '' }} · {{ a.action }}
        </li>
      </ul>
    </template>
  </section>
</template>
