<script setup lang="ts">
import { computed, nextTick, reactive, ref, useId } from 'vue'
import { ApiError } from '../client'
import type { Client } from '../client'
import { translator } from '../i18n'
import type { Locale } from '../i18n'
import type { Receipt, SubmitInput } from '../types'

const props = withDefaults(defineProps<{
  client: Pick<Client, 'submit'>
  /** The categories the host configured. Empty hides the field. */
  categories?: string[]
  locale?: Locale
}>(), { categories: () => [], locale: 'es' })

const emit = defineEmits<{ submitted: [receipt: Receipt] }>()

const tr = computed(() => translator(props.locale))
const t = (...a: Parameters<typeof tr.value.t>) => tr.value.t(...a)
const date = (iso: string) => tr.value.date(iso)
const id = useId()
const form = reactive({ subject: '', description: '', category: '', contact_name: '', contact_email: '' })
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const failure = ref('')
const receipt = ref<Receipt | null>(null)
const copied = ref(false)
const receivedHeading = ref<HTMLElement | null>(null)

async function submit() {
  busy.value = true
  failure.value = ''
  fieldErrors.value = {}
  // Optional fields left empty are not sent, so the report stays anonymous.
  const input: SubmitInput = { subject: form.subject.trim(), description: form.description.trim() }
  if (form.category) input.category = form.category
  if (form.contact_name.trim()) input.contact_name = form.contact_name.trim()
  if (form.contact_email.trim()) input.contact_email = form.contact_email.trim()

  try {
    receipt.value = await props.client.submit(input)
    Object.assign(form, { subject: '', description: '', category: '', contact_name: '', contact_email: '' })
    emit('submitted', receipt.value)
    await nextTick()
    receivedHeading.value?.focus()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) fieldErrors.value = e.errors
    else failure.value = e instanceof ApiError && e.status === 429 ? t('form.tooMany') : t('form.error')
  } finally {
    busy.value = false
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(receipt.value?.tracking_code ?? '')
    copied.value = true
  } catch {
    copied.value = false
  }
}

const describedBy = (field: string) => (fieldErrors.value[field] ? `${id}-${field}-err` : undefined)
</script>

<template>
  <section class="wb">
    <div v-if="receipt">
      <h2 ref="receivedHeading" tabindex="-1">{{ t('form.receivedTitle') }}</h2>
      <p>{{ t('form.codeLabel') }}</p>
      <p><code class="wb-code">{{ receipt.tracking_code }}</code></p>
      <p class="wb-warning">{{ t('form.codeWarning') }}</p>
      <button type="button" class="wb-button" @click="copy">{{ t('form.copy') }}</button>
      <span class="wb-sr-only" role="status">{{ copied ? t('form.copied') : '' }}</span>
      <p>{{ t('form.ackDue', { date: date(receipt.acknowledgement_due_at) }) }}</p>
    </div>

    <form v-else @submit.prevent="submit">
      <h2>{{ t('form.title') }}</h2>
      <p>{{ t('form.intro') }}</p>
      <p v-if="failure" class="wb-error" role="alert">{{ failure }}</p>

      <div class="wb-field">
        <label :for="`${id}-subject`">{{ t('form.subject') }}</label>
        <input :id="`${id}-subject`" v-model="form.subject" required maxlength="200"
               :aria-invalid="!!fieldErrors.subject" :aria-describedby="describedBy('subject')">
        <p v-if="fieldErrors.subject" :id="`${id}-subject-err`" class="wb-error">{{ fieldErrors.subject.join(' ') }}</p>
      </div>

      <div class="wb-field">
        <label :for="`${id}-description`">{{ t('form.description') }}</label>
        <textarea :id="`${id}-description`" v-model="form.description" required rows="8" maxlength="10000"
                  :aria-invalid="!!fieldErrors.description" :aria-describedby="describedBy('description')" />
        <p v-if="fieldErrors.description" :id="`${id}-description-err`" class="wb-error">{{ fieldErrors.description.join(' ') }}</p>
      </div>

      <div v-if="categories.length" class="wb-field">
        <label :for="`${id}-category`">{{ t('form.category') }}</label>
        <select :id="`${id}-category`" v-model="form.category"
                :aria-invalid="!!fieldErrors.category" :aria-describedby="describedBy('category')">
          <option value="">{{ t('form.categoryNone') }}</option>
          <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
        </select>
        <p v-if="fieldErrors.category" :id="`${id}-category-err`" class="wb-error">{{ fieldErrors.category.join(' ') }}</p>
      </div>

      <fieldset>
        <legend>{{ t('form.contactTitle') }}</legend>
        <p>{{ t('form.contactHint') }}</p>
        <div class="wb-field">
          <label :for="`${id}-contact_name`">{{ t('form.name') }}</label>
          <input :id="`${id}-contact_name`" v-model="form.contact_name" maxlength="150" autocomplete="off"
                 :aria-invalid="!!fieldErrors.contact_name" :aria-describedby="describedBy('contact_name')">
          <p v-if="fieldErrors.contact_name" :id="`${id}-contact_name-err`" class="wb-error">{{ fieldErrors.contact_name.join(' ') }}</p>
        </div>
        <div class="wb-field">
          <label :for="`${id}-contact_email`">{{ t('form.email') }}</label>
          <input :id="`${id}-contact_email`" v-model="form.contact_email" type="email" maxlength="254" autocomplete="off"
                 :aria-invalid="!!fieldErrors.contact_email" :aria-describedby="describedBy('contact_email')">
          <p v-if="fieldErrors.contact_email" :id="`${id}-contact_email-err`" class="wb-error">{{ fieldErrors.contact_email.join(' ') }}</p>
        </div>
      </fieldset>

      <button type="submit" class="wb-button" :disabled="busy">{{ busy ? t('form.submitting') : t('form.submit') }}</button>
    </form>
  </section>
</template>
