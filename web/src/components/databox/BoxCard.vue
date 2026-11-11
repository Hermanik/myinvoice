<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { databoxApi, type CheckResult, type DataboxBox, type SyncReport } from '@/api/databox'
import { useToast } from '@/composables/useToast'
import { boxTitle, databoxErrorMessage, errorCode, formatDateTime } from '@/utils/databox'
import CredentialRow from './CredentialRow.vue'
import CredentialForm from './CredentialForm.vue'

const props = defineProps<{ box: DataboxBox }>()
const emit = defineEmits<{
  (e: 'updated', box: DataboxBox): void
  (e: 'removed', id: number): void
}>()
const { t, te } = useI18n()
const toast = useToast()

const busy = ref(false)
const editing = ref<'sync' | 'action' | null>(null)
const checks = ref<{ sync?: CheckResult; action?: CheckResult } | null>(null)
const report = ref<SyncReport | null>(null)

const fail = (e: any) => toast.error(databoxErrorMessage(e, t, te))

async function run<T>(fn: () => Promise<T>, done?: (r: T) => void) {
  busy.value = true
  try {
    done?.(await fn())
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

const verify = () => run(() => databoxApi.verifyBox(props.box.id), r => {
  const { check, ...box } = r
  checks.value = check
  emit('updated', box as DataboxBox)
})

const syncNow = () => run(() => databoxApi.syncBox(props.box.id), r => {
  report.value = r.report
  emit('updated', r.box)
})

const toggleActive = () => run(() => databoxApi.updateBox(props.box.id, { is_active: !props.box.is_active }), b => emit('updated', b))

function toggleDelivering() {
  const enable = !props.box.allow_delivering_sync
  // Enabling makes every sync legally deliver messages, so it needs an explicit confirmation.
  if (enable && !window.confirm(t('databox.delivering.confirm'))) return
  run(() => databoxApi.updateBox(props.box.id, { allow_delivering_sync: enable }), b => emit('updated', b))
}

function toggleAccountant() {
  const enable = !props.box.accountant_visible
  // Opening a box shows its correspondence to another person, so it needs an explicit confirmation.
  if (enable && !window.confirm(t('databox.accountant.confirm'))) return
  run(() => databoxApi.updateBox(props.box.id, { accountant_visible: enable }), b => emit('updated', b))
}

function saveCredential(purpose: 'sync' | 'action', v: { username: string; password: string }) {
  run(() => databoxApi.setCredential(props.box.id, purpose, v), b => {
    editing.value = null
    checks.value = null
    emit('updated', b)
    toast.success(t('databox.credential_saved'))
  })
}

function removeAction() {
  if (!window.confirm(t('databox.remove_action_confirm'))) return
  run(() => databoxApi.removeCredential(props.box.id, 'action'), b => emit('updated', b))
}

async function removeBox() {
  if (!window.confirm(t('databox.remove_confirm'))) return
  busy.value = true
  try {
    await databoxApi.deleteBox(props.box.id)
    emit('removed', props.box.id)
  } catch (e: any) {
    if (errorCode(e) === 'confirm_required') {
      const count = e?.response?.data?.error?.message_count ?? 0
      if (window.confirm(t('databox.remove_messages_confirm', { count }))) {
        try {
          await databoxApi.deleteBox(props.box.id, true)
          emit('removed', props.box.id)
        } catch (e2) { fail(e2) }
      }
    } else {
      fail(e)
    }
  } finally {
    busy.value = false
  }
}

const emailOnly = computed(() => props.box.connection_mode === 'email')
const typeBadge = (type: string | null) =>
  type === 'FO' ? 'bg-primary-50 text-primary-700 border-primary-200' : 'bg-neutral-100 text-neutral-700 border-neutral-200'
const btn = 'cursor-pointer h-8 px-3 rounded-md border border-neutral-300 text-xs hover:bg-neutral-50 disabled:opacity-50'
</script>

<template>
  <div class="border border-neutral-200 rounded-md p-4 space-y-3" :class="{ 'opacity-70': !box.is_active }">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
      <span class="font-semibold">{{ boxTitle(box) }}</span>
      <span v-if="box.db_type" class="text-xs px-2 py-0.5 border rounded-full" :class="typeBadge(box.db_type)">{{ box.db_type }}</span>
      <code v-if="box.db_id" class="text-xs bg-neutral-100 rounded px-1.5 py-0.5">{{ box.db_id }}</code>
      <span v-if="box.owner_name" class="text-sm text-neutral-600">{{ box.owner_name }}</span>
      <span v-if="emailOnly" class="text-xs px-2 py-0.5 rounded-full border bg-primary-50 text-primary-700 border-primary-200" data-test="email-only-badge">
        {{ t('databox.mode.email_badge') }}
      </span>
      <span v-else class="text-xs px-2 py-0.5 rounded-full border"
        :class="box.environment === 'test' ? 'bg-warning-50 text-warning-600 border-warning-500/40' : 'bg-neutral-50 text-neutral-600 border-neutral-200'">
        {{ t(`databox.env.${box.environment}`) }}
      </span>
      <span class="text-xs px-2 py-0.5 rounded-full border"
        :class="box.is_active ? 'bg-success-50 text-success-600 border-success-500/40' : 'bg-neutral-100 text-neutral-500 border-neutral-200'">
        {{ box.is_active ? t('common.active') : t('common.inactive') }}
      </span>
    </div>

    <p v-if="emailOnly" class="text-sm text-neutral-700" data-test="email-only-note">{{ t('databox.mode.email_note') }}</p>

    <template v-else>
      <p class="text-xs text-neutral-500">
        <template v-if="box.last_sync_at">
          {{ t('databox.last_sync', { date: formatDateTime(box.last_sync_at) }) }}
          <span v-if="box.last_sync_status" :class="box.last_sync_status === 'ok' ? 'text-success-600' : 'text-danger-500'">
            · {{ t(`databox.sync_status.${box.last_sync_status}`) }}
          </span>
          <span v-if="box.last_sync_message"> · {{ box.last_sync_message }}</span>
        </template>
        <template v-else>{{ t('databox.never_synced') }}</template>
        <span v-if="box.sync_paused_until" class="text-warning-600"> · {{ t('databox.paused_until', { date: formatDateTime(box.sync_paused_until) }) }}</span>
      </p>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div>
          <CredentialRow v-if="box.credentials.sync" purpose="sync" :cred="box.credentials.sync" :sync-state="box.sync.state" />
          <div class="mt-2 flex gap-2">
            <button type="button" :class="btn" :disabled="busy" @click="editing = editing === 'sync' ? null : 'sync'">{{ t('databox.replace_login') }}</button>
          </div>
          <CredentialForm v-if="editing === 'sync'" :busy="busy" :initial-username="box.credentials.sync?.username"
            @submit="saveCredential('sync', $event)" @cancel="editing = null" />
        </div>
        <div>
          <CredentialRow v-if="box.credentials.action" purpose="action" :cred="box.credentials.action" />
          <p v-else class="text-sm text-neutral-500">{{ t('databox.no_action_login') }}</p>
          <div class="mt-2 flex gap-2">
            <button type="button" :class="btn" :disabled="busy" @click="editing = editing === 'action' ? null : 'action'">
              {{ box.credentials.action ? t('databox.replace_login') : t('databox.add_action_login') }}
            </button>
            <button v-if="box.credentials.action" type="button" :class="btn" :disabled="busy" @click="removeAction">{{ t('databox.remove_action_login') }}</button>
          </div>
          <CredentialForm v-if="editing === 'action'" :busy="busy" :initial-username="box.credentials.action?.username"
            @submit="saveCredential('action', $event)" @cancel="editing = null" />
        </div>
      </div>

      <!-- Opt-in delivering sync: explicit red warning, off by default -->
      <div class="rounded-md border px-3 py-2" :class="box.allow_delivering_sync ? 'border-danger-500/40 bg-danger-50' : 'border-neutral-200'">
        <label class="flex items-start gap-2 text-sm cursor-pointer">
          <input type="checkbox" class="mt-1" :checked="box.allow_delivering_sync" :disabled="busy" @click.prevent="toggleDelivering" />
          <span>
            <span class="font-medium">{{ t('databox.delivering.label') }}</span>
            <span class="block text-xs text-danger-500">{{ t('databox.delivering.warning') }}</span>
          </span>
        </label>
      </div>
    </template>

    <!-- Opens the box to the accountant role (read-only, whatever the connection mode); off by default -->
    <div class="rounded-md border border-neutral-200 px-3 py-2">
      <label class="flex items-start gap-2 text-sm cursor-pointer">
        <input type="checkbox" class="mt-1" :checked="box.accountant_visible" :disabled="busy" data-test="accountant-visible" @click.prevent="toggleAccountant" />
        <span>
          <span class="font-medium">{{ t('databox.accountant.label') }}</span>
          <span class="block text-xs text-neutral-500">{{ t('databox.accountant.hint') }}</span>
        </span>
      </label>
    </div>

    <div v-if="checks" class="space-y-1">
      <p v-for="p in (['sync', 'action'] as const)" v-show="checks[p]" :key="p" class="text-xs rounded px-2 py-1"
        :class="checks[p]?.ok ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
        {{ t(`databox.purpose.${p}`) }}: {{ checks[p]?.ok ? t('databox.check_ok') : (checks[p]?.message || checks[p]?.reason) }}
      </p>
    </div>
    <div v-if="report" class="text-xs rounded px-2 py-1 border border-neutral-200 bg-neutral-50">
      <strong>{{ t(`databox.report_status.${report.status}`) }}</strong>
      <span v-if="report.message"> – {{ report.message }}</span>
      <span class="block">{{ t('databox.report_counts', { received: report.received_new, tax: report.received_new_tax_office, sent: report.sent_new, updated: report.updated, receipts: report.receipts }) }}</span>
    </div>

    <div class="flex flex-wrap gap-2 pt-1">
      <button v-if="!emailOnly" type="button" :class="btn" :disabled="busy" @click="verify">{{ t('databox.verify') }}</button>
      <button v-if="!emailOnly" type="button" :class="btn" :disabled="busy || !box.is_active" @click="syncNow">{{ t('databox.sync_now') }}</button>
      <button type="button" :class="btn" :disabled="busy" @click="toggleActive">{{ box.is_active ? t('databox.deactivate') : t('databox.activate') }}</button>
      <button type="button" class="cursor-pointer h-8 px-3 rounded-md border border-danger-500/40 text-danger-500 text-xs hover:bg-danger-50 disabled:opacity-50" :disabled="busy" @click="removeBox">
        {{ t('databox.remove_box') }}
      </button>
    </div>
  </div>
</template>
