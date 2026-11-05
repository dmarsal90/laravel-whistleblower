<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { ApiError } from '../client'
import type { Client } from '../client'
import { translator } from '../i18n'
import type { Locale } from '../i18n'
import type { FollowUpData } from '../types'

const props = withDefaults(defineProps<{
  client: Pick<Client, 'followUp' | 'reply'>
  locale?: Locale
}>(), { locale: 'es' })

const tr = computed(() => translator(props.locale))
const t = (...a: Parameters<typeof tr.value.t>) => tr.value.t(...a)
const date = (iso: string) => tr.value.date(iso)
const id = useId()

const codeInput = ref('')
// The code lives only in memory and is only ever sent in request bodies.
const activeCode = ref('')
const report = ref<FollowUpData | null>(null)
const busy = ref(false)
const failure = ref('')
const reply = ref('')
const replyBusy = ref(false)
const replyFailure = ref('')

function message(e: unknown, fallback: 'follow.error' | 'follow.notFound'): string {
  if (e instanceof ApiError && e.status === 429) return t('follow.tooMany')
  if (e instanceof ApiError && e.status === 404) return t('follow.notFound')
  return t(fallback)
}

async function check() {
  busy.value = true
  failure.value = ''
  try {
    const code = codeInput.value.trim()
    report.value = await props.client.followUp(code)
    activeCode.value = code
  } catch (e) {
    report.value = null
    failure.value = message(e, 'follow.error')
  } finally {
    busy.value = false
  }
}

async function send() {
  replyBusy.value = true
  replyFailure.value = ''
  try {
    await props.client.reply(activeCode.value, reply.value.trim())
    reply.value = ''
    report.value = await props.client.followUp(activeCode.value)
  } catch (e) {
    // A closed report answers 409 with a readable message from the server.
    replyFailure.value = e instanceof ApiError && e.status === 409 ? e.message : message(e, 'follow.error')
  } finally {
    replyBusy.value = false
  }
}
</script>

<template>
  <section class="wb">
    <form @submit.prevent="check">
      <h2>{{ t('follow.title') }}</h2>
      <p>{{ t('follow.intro') }}</p>
      <div class="wb-field">
        <label :for="`${id}-code`">{{ t('follow.code') }}</label>
        <input :id="`${id}-code`" v-model="codeInput" required maxlength="40" autocomplete="off"
               autocapitalize="characters" spellcheck="false" :aria-invalid="!!failure"
               :aria-describedby="failure ? `${id}-err` : undefined">
        <p v-if="failure" :id="`${id}-err`" class="wb-error" role="alert">{{ failure }}</p>
      </div>
      <button type="submit" class="wb-button" :disabled="busy">{{ busy ? t('follow.checking') : t('follow.check') }}</button>
    </form>

    <div v-if="report" class="wb-result">
      <h3>{{ t('follow.status') }}: {{ tr.status(report.status) }}</h3>
      <ul class="wb-dates">
        <li>{{ t('follow.received', { date: date(report.received_at) }) }}</li>
        <li v-if="report.acknowledged_at">{{ t('follow.acknowledged', { date: date(report.acknowledged_at) }) }}</li>
        <li v-if="report.resolution_due_at">{{ t('follow.due', { date: date(report.resolution_due_at) }) }}</li>
      </ul>

      <template v-if="report.resolution">
        <h3>{{ t('follow.resolution') }}</h3>
        <p class="wb-text">{{ report.resolution }}</p>
      </template>

      <h3>{{ t('follow.messages') }}</h3>
      <p v-if="!report.messages.length">{{ t('follow.noMessages') }}</p>
      <ul v-else class="wb-messages">
        <li v-for="(m, i) in report.messages" :key="i">
          <strong>{{ m.author === 'reporter' ? t('follow.you') : t('follow.team') }}</strong>
          <time :datetime="m.created_at">{{ date(m.created_at) }}</time>
          <p class="wb-text">{{ m.body }}</p>
        </li>
      </ul>

      <form @submit.prevent="send">
        <div class="wb-field">
          <label :for="`${id}-reply`">{{ t('follow.reply') }}</label>
          <textarea :id="`${id}-reply`" v-model="reply" required rows="4" maxlength="5000"
                    :aria-invalid="!!replyFailure" :aria-describedby="replyFailure ? `${id}-reply-err` : undefined" />
          <p v-if="replyFailure" :id="`${id}-reply-err`" class="wb-error" role="alert">{{ replyFailure }}</p>
        </div>
        <button type="submit" class="wb-button" :disabled="replyBusy">{{ replyBusy ? t('follow.sending') : t('follow.send') }}</button>
      </form>
    </div>
  </section>
</template>
