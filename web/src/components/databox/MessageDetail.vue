<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { databoxApi, type MessageDetail } from '@/api/databox'
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { formatBytes } from '@/components/documents/docFormat'
import { databoxErrorMessage, errorCode, formatDateTime, stateBadgeClass } from '@/utils/databox'
import OpenConfirmModal from './OpenConfirmModal.vue'

const props = defineProps<{ messageId: number }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed', d: MessageDetail): void }>()
const { t, te } = useI18n()
const toast = useToast()

const detail = ref<MessageDetail | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const showAck = ref(false)
// Values from a 409 delivery_ack_required answer take precedence over the (possibly stale) detail.
const ackOverride = ref<{ undelivered: boolean; fiction_date: string | null } | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  detail.value = null
  try {
    detail.value = await databoxApi.getMessage(props.messageId)
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  } finally {
    loading.value = false
  }
}
watch(() => props.messageId, load, { immediate: true })

function apply(d: MessageDetail) {
  detail.value = d
  emit('changed', d)
}

const isReceived = computed(() => detail.value?.direction === 'received')
const capableMissing = computed(() => detail.value?.open.has_capable_login === false)
const openBlocked = computed(() => detail.value?.open.blocked_reason ?? null)
const canOpen = computed(() => isReceived.value && !detail.value?.open.stored && !capableMissing.value && !openBlocked.value)

// Opening: warn first when it would deliver the message; only the confirmation path passes acknowledged=true.
function openClick() {
  if (!detail.value) return
  ackOverride.value = null
  if (detail.value.open.needs_acknowledgement) showAck.value = true
  else void doOpen(false)
}
const confirmOpen = () => doOpen(true)

async function doOpen(acknowledged: boolean) {
  busy.value = true
  error.value = ''
  try {
    apply(await databoxApi.openMessage(props.messageId, acknowledged))
    showAck.value = false
  } catch (e: any) {
    const code = errorCode(e)
    if (code === 'delivery_ack_required') {
      const body = e?.response?.data?.error ?? {}
      ackOverride.value = { undelivered: !!body.undelivered, fiction_date: body.fiction_date ?? null }
      showAck.value = true
    } else {
      showAck.value = false
      error.value = databoxErrorMessage(e, t, te)
    }
  } finally {
    busy.value = false
  }
}

async function simple(fn: () => Promise<unknown>, after?: () => void) {
  busy.value = true
  try { await fn(); after?.() } catch (e) { toast.error(databoxErrorMessage(e, t, te)) } finally { busy.value = false }
}

const acknowledge = () => simple(() => databoxApi.acknowledge(props.messageId), load)
const loadReceipt = () => simple(async () => apply(await databoxApi.fetchReceipt(props.messageId)))

const hintKey = (code: string) => `databox.hints.${code}`
const field = 'text-neutral-500'
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-4 text-sm">
    <div class="flex items-start justify-between gap-2">
      <h2 class="font-semibold text-base">{{ detail?.annotation || t('databox.detail.no_subject') }}</h2>
      <button type="button" class="cursor-pointer text-neutral-500 hover:text-neutral-900" :aria-label="t('common.close')" @click="emit('close')">✕</button>
    </div>

    <p v-if="loading" class="text-neutral-500">{{ t('common.loading') }}</p>
    <p v-if="error" class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2" role="alert">{{ error }}</p>

    <template v-if="detail">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs px-2 py-0.5 border rounded-full" :class="stateBadgeClass(detail.state)">{{ t(`databox.state.${detail.state}`) }}</span>
        <span v-if="detail.is_tax_office" class="text-xs px-2 py-0.5 rounded-full bg-danger-50 text-danger-500 border border-danger-500/40">{{ t('databox.tax_office') }}</span>
        <span v-if="detail.personal_delivery" class="text-xs px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-700 border border-neutral-200">{{ t('databox.personal_delivery') }}</span>
      </div>
      <p class="text-xs text-neutral-600">{{ t(`databox.state_help.${detail.state}`) }}</p>

      <dl class="grid grid-cols-[9rem_1fr] gap-y-1">
        <dt :class="field">{{ t('databox.detail.sender') }}</dt><dd>{{ detail.sender_name || '—' }} <code class="text-xs">{{ detail.sender_box_id }}</code></dd>
        <dt :class="field">{{ t('databox.detail.recipient') }}</dt><dd>{{ detail.recipient_name || '—' }} <code class="text-xs">{{ detail.recipient_box_id }}</code></dd>
        <dt :class="field">{{ t('databox.detail.dm_id') }}</dt><dd class="font-mono">{{ detail.dm_id }}</dd>
        <dt :class="field">{{ t('databox.detail.delivery_time') }}</dt><dd>{{ formatDateTime(detail.delivery_time) }}</dd>
        <dt :class="field">{{ t('databox.detail.acceptance_time') }}</dt><dd>{{ formatDateTime(detail.acceptance_time) }}</dd>
        <template v-if="detail.to_hands"><dt :class="field">{{ t('databox.field.to_hands') }}</dt><dd>{{ detail.to_hands }}</dd></template>
        <template v-if="detail.sender_ref_number"><dt :class="field">{{ t('databox.field.sender_ref_number') }}</dt><dd>{{ detail.sender_ref_number }}</dd></template>
        <template v-if="detail.recipient_ref_number"><dt :class="field">{{ t('databox.field.recipient_ref_number') }}</dt><dd>{{ detail.recipient_ref_number }}</dd></template>
        <dt :class="field">{{ t('databox.detail.attachment_size') }}</dt><dd>{{ detail.attachment_size_kb != null ? formatBytes(detail.attachment_size_kb * 1024) : '—' }}</dd>
      </dl>

      <!-- Indicative deadlines for tax office calls -->
      <div v-if="detail.tax_office_hints.length" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 space-y-1" data-test="tax-hints">
        <p v-for="h in detail.tax_office_hints" :key="h.code" class="text-danger-500">
          {{ t(hintKey(h.code)) }} –
          <strong>{{ h.due ? t('databox.hints.due', { date: formatDate(h.due) }) : t('databox.hints.after_delivery') }}</strong>
        </p>
        <p class="text-xs text-neutral-600">{{ t('databox.hints.binding') }}</p>
      </div>

      <!-- Open / download -->
      <div v-if="isReceived" class="space-y-2">
        <p v-if="detail.open.fiction_date && detail.open.undelivered" class="text-xs text-warning-600">
          {{ t('databox.open.fiction_date', { date: formatDate(detail.open.fiction_date) }) }}
        </p>
        <p v-if="capableMissing && !detail.open.stored" class="rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-2" data-test="no-capable-login">
          {{ t('databox.open.no_capable_login') }}
          <router-link to="/admin/settings#databox" class="underline">{{ t('databox.open.go_settings') }}</router-link>
        </p>
        <p v-if="openBlocked && !detail.open.stored" class="rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-2" data-test="open-blocked">
          {{ t(`databox.open.${openBlocked}`) }}
        </p>
        <button v-if="canOpen" type="button" :disabled="busy"
          class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" @click="openClick">
          {{ t('databox.open.button') }}
        </button>
      </div>

      <div v-if="detail.attachments?.length">
        <h3 class="font-medium mb-1">{{ t('databox.detail.attachments') }}</h3>
        <ul class="divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <li v-for="a in detail.attachments" :key="a.index" class="flex items-center justify-between px-3 py-1.5">
            <span>{{ a.name }} <span class="text-xs text-neutral-500">({{ formatBytes(a.size) }})</span></span>
            <a :href="databoxApi.attachmentUrl(detail.id, a.index)" target="_blank" rel="noopener" class="text-primary-700 hover:underline text-xs">{{ t('common.download') }}</a>
          </li>
        </ul>
      </div>

      <div class="flex flex-wrap gap-2">
        <a v-if="detail.has_zfo" :href="databoxApi.zfoUrl(detail.id)" target="_blank" rel="noopener"
          class="h-8 px-3 inline-flex items-center rounded-md border border-neutral-300 text-xs hover:bg-neutral-50">{{ t('databox.detail.download_zfo') }}</a>
        <a v-if="detail.has_delivery_zfo" :href="databoxApi.zfoUrl(detail.id, 'delivery_zfo')" target="_blank" rel="noopener"
          class="h-8 px-3 inline-flex items-center rounded-md border border-neutral-300 text-xs hover:bg-neutral-50">{{ t('databox.detail.download_receipt') }}</a>
        <button v-if="!isReceived" type="button" :disabled="busy" class="cursor-pointer h-8 px-3 rounded-md border border-neutral-300 text-xs hover:bg-neutral-50 disabled:opacity-50" @click="loadReceipt">
          {{ t('databox.detail.load_receipt') }}
        </button>
        <button v-if="isReceived && !detail.acknowledged_at" type="button" :disabled="busy" class="cursor-pointer h-8 px-3 rounded-md border border-neutral-300 text-xs hover:bg-neutral-50 disabled:opacity-50" @click="acknowledge">
          {{ t('databox.detail.acknowledge') }}
        </button>
      </div>

      <div v-if="!isReceived && detail.receipt?.events?.length">
        <h3 class="font-medium mb-1">{{ t('databox.detail.receipt_events') }}</h3>
        <ul class="text-xs space-y-0.5">
          <li v-for="(ev, i) in detail.receipt.events" :key="i"><span class="text-neutral-500">{{ formatDateTime(ev.time) }}</span> – {{ ev.text }}</li>
        </ul>
      </div>
    </template>

    <OpenConfirmModal v-if="showAck && detail" :undelivered="(ackOverride ?? detail.open).undelivered"
      :fiction-date="(ackOverride ?? detail.open).fiction_date" :busy="busy" @close="showAck = false" @confirm="confirmOpen" />
  </div>
</template>
