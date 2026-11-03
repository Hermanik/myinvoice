<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { api } from '@/api/client'
import { databoxApi, type MessageDetail, type PrepareResponse } from '@/api/databox'
import { formatBytes } from '@/components/documents/docFormat'
import { boxTitle, databoxErrorMessage, errorCode, formatDateTime, warningText } from '@/utils/databox'

/**
 * The SINGLE confirmation step for sending a data box message (compose and tax filing).
 * This is the only place in the app that calls the send endpoint, and only on a click.
 */
const props = defineProps<{ prepared: PrepareResponse }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'sent', result: SendResult): void
  (e: 'expired'): void
}>()
const { t, te } = useI18n()

interface SendResult { message_id: number; dm_id: string; submission_id: number | null; receipt: boolean; zfo: boolean; message: MessageDetail }

const summary = computed(() => props.prepared.summary)
const busy = ref(false)
const resendConfirmed = ref(false)
const unvalidatedConfirmed = ref(false)
const error = ref('')
const result = ref<SendResult | null>(null)

const canSend = computed(() => !busy.value
  && (!summary.value.requires_resend_confirmation || resendConfirmed.value)
  && (!summary.value.requires_unvalidated_confirmation || unvalidatedConfirmed.value))


async function send() {
  if (!canSend.value) return
  busy.value = true
  error.value = ''
  try {
    const body: { token: string; confirmed: true; resend_confirmed?: boolean; unvalidated_confirmed?: boolean } = { token: props.prepared.token, confirmed: true }
    if (summary.value.requires_resend_confirmation) body.resend_confirmed = true
    if (summary.value.requires_unvalidated_confirmation) body.unvalidated_confirmed = true
    const r = await api.post<SendResult>('/databox/messages/send', body)
    result.value = r.data
    emit('sent', r.data)
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
    if (errorCode(e) === 'token_invalid') emit('expired')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="result ? t('databox.send.done_title') : t('databox.send.title')" width-class="max-w-2xl" @close="emit('close')">
    <!-- Result -->
    <div v-if="result" class="space-y-3 text-sm">
      <p class="rounded-md border border-success-500/40 bg-success-50 text-success-600 px-3 py-2">{{ t('databox.send.done') }}</p>
      <dl class="grid grid-cols-[10rem_1fr] gap-y-1">
        <dt class="text-neutral-500">{{ t('databox.send.dm_id') }}</dt><dd class="font-mono">{{ result.dm_id }}</dd>
        <dt class="text-neutral-500">{{ t('databox.send.state') }}</dt><dd>{{ t(`databox.state.${result.message.state}`) }}</dd>
        <dt class="text-neutral-500">{{ t('databox.send.receipt') }}</dt>
        <dd>
          <a v-if="result.message.has_delivery_zfo" :href="databoxApi.zfoUrl(result.message_id, 'delivery_zfo')" target="_blank" rel="noopener" class="text-primary-700 hover:underline">{{ t('databox.detail.download_receipt') }}</a>
          <span v-else class="text-neutral-500">{{ t('databox.send.receipt_pending') }}</span>
        </dd>
      </dl>
      <router-link :to="{ path: '/databox', query: { box: String(result.message.databox_id), message: String(result.message_id) } }" class="text-primary-700 hover:underline inline-block" @click="emit('close')">
        {{ t('databox.send.open_message') }}
      </router-link>
    </div>

    <!-- Confirmation -->
    <div v-else class="space-y-4 text-sm">
      <p class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2 font-medium">{{ t('databox.send.legal_warning') }}</p>

      <dl class="grid grid-cols-[10rem_1fr] gap-y-1.5">
        <dt class="text-neutral-500">{{ t('databox.send.from') }}</dt>
        <dd>
          {{ boxTitle(summary.box) }}
          <span class="text-xs px-1.5 py-0.5 border rounded-full bg-neutral-50">{{ summary.box.db_type }}</span>
          <code class="text-xs ml-1">{{ summary.box.db_id }}</code>
        </dd>
        <dt class="text-neutral-500">{{ t('databox.send.to') }}</dt>
        <dd>
          {{ summary.recipient.name || '—' }} <code class="text-xs ml-1">{{ summary.recipient.box_id }}</code>
          <span v-if="!summary.recipient.verified" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 border border-warning-500/40">{{ t('databox.send.unverified') }}</span>
          <span v-if="summary.recipient.is_tax_office" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 border border-danger-500/40">{{ t('databox.tax_office') }}</span>
        </dd>
        <dt class="text-neutral-500">{{ t('databox.field.annotation') }}</dt><dd>{{ summary.annotation }}</dd>
        <template v-if="summary.to_hands"><dt class="text-neutral-500">{{ t('databox.field.to_hands') }}</dt><dd>{{ summary.to_hands }}</dd></template>
        <template v-if="summary.personal_delivery"><dt class="text-neutral-500">{{ t('databox.field.personal_delivery') }}</dt><dd>{{ t('common.yes') }}</dd></template>
        <template v-if="summary.form"><dt class="text-neutral-500">{{ t('databox.send.form') }}</dt><dd>{{ summary.form.form_name }} · {{ summary.form.period }}</dd></template>
      </dl>

      <table class="w-full text-xs border border-neutral-200">
        <thead class="bg-neutral-50 text-neutral-500">
          <tr>
            <th class="text-left px-2 py-1.5">{{ t('databox.send.file') }}</th>
            <th class="text-right px-2 py-1.5">{{ t('databox.send.size') }}</th>
            <th class="text-left px-2 py-1.5">SHA-256</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="f in summary.files" :key="f.name + f.sha256">
            <td class="px-2 py-1.5">{{ f.name }}</td>
            <td class="px-2 py-1.5 text-right font-mono">{{ formatBytes(f.size) }}</td>
            <td class="px-2 py-1.5 font-mono" :title="f.sha256">{{ f.sha256.slice(0, 12) }}…</td>
          </tr>
        </tbody>
        <tfoot><tr class="bg-neutral-50"><td class="px-2 py-1.5 font-medium">{{ t('databox.send.total') }}</td><td class="px-2 py-1.5 text-right font-mono">{{ formatBytes(summary.total_bytes) }}</td><td></td></tr></tfoot>
      </table>

      <ul v-if="summary.warnings.length" class="space-y-1">
        <li v-for="w in summary.warnings" :key="w" class="rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-1.5">{{ warningText(w, t, te) }}</li>
      </ul>

      <div v-if="summary.previous_sends.length">
        <h3 class="font-medium mb-1">{{ t('databox.send.previous') }}</h3>
        <table class="w-full text-xs border border-neutral-200">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="p in summary.previous_sends" :key="p.message_id">
              <td class="px-2 py-1.5 font-mono">{{ p.dm_id }}</td>
              <td class="px-2 py-1.5">{{ p.recipient_name || p.recipient_box_id }}</td>
              <td class="px-2 py-1.5">{{ formatDateTime(p.acceptance_time || p.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <label v-if="summary.requires_resend_confirmation" class="flex items-start gap-2 rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 cursor-pointer">
        <input v-model="resendConfirmed" type="checkbox" class="mt-0.5" />
        <span class="text-danger-500 font-medium">{{ t('databox.send.resend_checkbox') }}</span>
      </label>

      <label v-if="summary.requires_unvalidated_confirmation" class="flex items-start gap-2 rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 cursor-pointer" data-test="unvalidated-confirm">
        <input v-model="unvalidatedConfirmed" type="checkbox" class="mt-0.5" />
        <span class="text-danger-500 font-medium">{{ t('databox.send.unvalidated_checkbox') }}</span>
      </label>

      <p class="text-xs text-neutral-500">{{ t('databox.send.expires', { date: formatDateTime(prepared.expires_at) }) }}</p>
      <p v-if="error" class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2" role="alert">{{ error }}</p>
    </div>

    <template #footer>
      <button type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('close')">
        {{ result ? t('common.close') : t('common.cancel') }}
      </button>
      <button v-if="!result" type="button" :disabled="!canSend"
        class="cursor-pointer h-9 px-4 rounded-md bg-danger-500 text-white text-sm font-medium hover:bg-danger-600 disabled:opacity-50" @click="send">
        {{ busy ? t('databox.send.sending') : t('databox.send.submit') }}
      </button>
    </template>
  </Modal>
</template>
