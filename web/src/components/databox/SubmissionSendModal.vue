<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { databoxApi, type PrepareResponse, type SubmissionPrefill } from '@/api/databox'
import { formatBytes } from '@/components/documents/docFormat'
import { boxTitle, databoxErrorMessage, formatDateTime, warningText } from '@/utils/databox'
import SendConfirmModal from './SendConfirmModal.vue'

const props = defineProps<{ submissionId: number }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'sent'): void }>()
const { t, te } = useI18n()

const prefill = ref<SubmissionPrefill | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const boxId = ref<number | null>(null)
const recipientOverride = ref('')
const editRecipient = ref(false)
const annotation = ref('')
const prepared = ref<PrepareResponse | null>(null)

const sendableBoxes = computed(() => (prefill.value?.boxes ?? []).filter(b => b.is_active && b.can_send))
const blocked = computed(() => prefill.value?.blocked_reason === 'xsd_failed' || prefill.value?.blocked_reason === 'integrity_failed')
const effectiveRecipient = computed(() => (editRecipient.value || !prefill.value?.recipient.box_id) ? recipientOverride.value.trim() : prefill.value.recipient.box_id)
const canContinue = computed(() => !busy.value && !blocked.value && boxId.value !== null && effectiveRecipient.value?.length === 7 && annotation.value.trim().length > 0)

onMounted(async () => {
  try {
    const p = await databoxApi.submissionPrefill(props.submissionId)
    prefill.value = p
    annotation.value = p.annotation
    boxId.value = sendableBoxes.value.find(b => b.id === p.recommended_box_id)?.id ?? sendableBoxes.value[0]?.id ?? null
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  } finally {
    loading.value = false
  }
})

async function proceed() {
  if (!canContinue.value || boxId.value === null) return
  busy.value = true
  error.value = ''
  try {
    prepared.value = await databoxApi.prepareSubmission(props.submissionId, {
      box_id: boxId.value,
      recipient_box_id: effectiveRecipient.value || undefined,
      annotation: annotation.value.trim(),
    })
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  } finally {
    busy.value = false
  }
}

const warnText = (w: string) => warningText(w, t, te)
const input = 'w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface'
const lbl = 'block text-xs font-medium text-neutral-700 mb-1'
</script>

<template>
  <SendConfirmModal v-if="prepared" :prepared="prepared" @close="prepared = null; emit('close')" @expired="prepared = null" @sent="emit('sent')" />
  <Modal v-else :title="t('databox.submission.title')" width-class="max-w-2xl" @close="emit('close')">
    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-if="error" class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2 text-sm mb-3" role="alert">{{ error }}</p>

    <div v-if="prefill" class="space-y-3 text-sm">
      <p class="font-medium">{{ prefill.submission.form_name }} · {{ prefill.submission.period_label }}</p>

      <p v-if="blocked" class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2 font-medium" data-test="xsd-blocked">{{ t(`databox.submission.${prefill.blocked_reason}`) }}</p>
      <ul v-if="prefill.warnings.length" class="space-y-1">
        <li v-for="w in prefill.warnings" :key="w" class="rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-1.5">{{ warnText(w) }}</li>
      </ul>

      <div>
        <label :class="lbl">{{ t('databox.submission.recipient') }}</label>
        <p v-if="prefill.recipient.box_id && !editRecipient">
          {{ prefill.recipient.name }} <code class="text-xs">{{ prefill.recipient.box_id }}</code>
          <span class="text-xs text-neutral-500">({{ t(`databox.submission.source_${prefill.recipient.source}`) }})</span>
          <button type="button" class="cursor-pointer ml-2 text-xs text-primary-700 hover:underline" @click="editRecipient = true">{{ t('databox.submission.override') }}</button>
        </p>
        <input v-else v-model="recipientOverride" type="text" maxlength="7" :placeholder="t('databox.compose.recipient_id')" :class="[input, 'font-mono']" />
      </div>

      <div>
        <label :class="lbl">{{ t('databox.compose.from_box') }}</label>
        <select v-model="boxId" :class="input">
          <option v-for="b in sendableBoxes" :key="b.id" :value="b.id">{{ boxTitle(b) }} ({{ b.db_type }} · {{ b.db_id }})</option>
        </select>
        <p v-if="!sendableBoxes.length" class="text-xs text-danger-500 mt-1">{{ t('databox.submission.no_box') }}</p>
      </div>

      <div>
        <label :class="lbl">{{ t('databox.field.annotation') }} <span class="text-neutral-400">({{ annotation.length }}/255)</span></label>
        <input v-model="annotation" type="text" maxlength="255" :class="input" />
      </div>
      <p class="text-xs text-neutral-600">{{ t('databox.submission.file') }}: {{ prefill.file_name }} ({{ formatBytes(prefill.file_size) }})</p>

      <div v-if="prefill.previous_sends.length">
        <h3 class="font-medium mb-1">{{ t('databox.send.previous') }}</h3>
        <ul class="text-xs space-y-0.5">
          <li v-for="p in prefill.previous_sends" :key="p.message_id">
            <span class="font-mono">{{ p.dm_id }}</span> · {{ p.recipient_name || p.recipient_box_id }} · {{ formatDateTime(p.acceptance_time || p.created_at) }}
          </li>
        </ul>
      </div>
    </div>

    <template #footer>
      <button type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" :disabled="!canContinue" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" @click="proceed">
        {{ busy ? t('common.loading') : t('databox.submission.continue') }}
      </button>
    </template>
  </Modal>
</template>
