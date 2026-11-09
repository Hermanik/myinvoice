<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { databoxApi, type EmailImapAccount, type EmailNoticeSettings, type EmailScanSummary } from '@/api/databox'
import { useToast } from '@/composables/useToast'
import { databoxErrorMessage, formatDateTime } from '@/utils/databox'

const { t, te } = useI18n()
const toast = useToast()

const loaded = ref(false)
const busy = ref(false)
const accounts = ref<EmailImapAccount[]>([])
const saved = ref<EmailNoticeSettings | null>(null)
const summary = ref<EmailScanSummary | null>(null)

const accountId = ref<number | ''>('')
const folder = ref('INBOX')
const alertEmail = ref('')
const enabled = ref(false)

function apply(r: { settings: EmailNoticeSettings; imap_accounts: EmailImapAccount[] }) {
  saved.value = r.settings
  accounts.value = r.imap_accounts
  accountId.value = r.settings.imap_account_id ?? ''
  folder.value = r.settings.folder
  alertEmail.value = r.settings.alert_email ?? ''
  enabled.value = r.settings.enabled
}

const accountIdOrNull = computed(() => (accountId.value === '' ? null : accountId.value))
const dirty = computed(() => saved.value !== null && (
  accountIdOrNull.value !== saved.value.imap_account_id
  || folder.value.trim() !== saved.value.folder
  || alertEmail.value.trim() !== (saved.value.alert_email ?? '')
  || enabled.value !== saved.value.enabled
))

async function load() {
  try {
    apply(await databoxApi.emailSettings())
  } catch (e) {
    toast.error(databoxErrorMessage(e, t, te))
  } finally {
    loaded.value = true
  }
}

const body = () => ({
  imap_account_id: accountIdOrNull.value,
  folder: folder.value.trim(),
  enabled: enabled.value,
  alert_email: alertEmail.value.trim() || null,
})

async function save(): Promise<boolean> {
  busy.value = true
  try {
    apply(await databoxApi.saveEmailSettings(body()))
    toast.success(t('databox.email.saved'))
    return true
  } catch (e) {
    toast.error(databoxErrorMessage(e, t, te))
    return false
  } finally {
    busy.value = false
  }
}

// "Zkontrolovat teď" reads the mailbox with the settings as they are shown, so unsaved changes are saved first.
async function scan() {
  if (dirty.value && !(await save())) return
  busy.value = true
  try {
    const r = await databoxApi.scanEmail()
    summary.value = r.summary
    apply(r)
  } catch (e) {
    toast.error(databoxErrorMessage(e, t, te))
  } finally {
    busy.value = false
  }
}

onMounted(load)

const input = 'w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface'
const lbl = 'block text-xs font-medium text-neutral-700 mb-1'
const btn = 'cursor-pointer h-8 px-3 rounded-md border border-neutral-300 text-xs hover:bg-neutral-50 disabled:opacity-50'
</script>

<template>
  <details class="rounded-md border border-neutral-200" data-test="email-notice-settings" :open="enabled || undefined">
    <summary class="cursor-pointer px-3 py-2 text-sm font-medium">{{ t('databox.email.title') }}</summary>
    <div class="px-4 pb-4 space-y-3">
      <p class="text-sm text-neutral-700 mt-2">{{ t('databox.email.intro') }}</p>
      <p class="text-xs text-neutral-500">
        {{ t('databox.email.how') }}
        <a href="/manual?ch=42_Datove_schranky" target="_blank" rel="noopener" class="text-primary-700 hover:underline">{{ t('databox.help.manual_link') }}</a>
      </p>

      <p v-if="!loaded" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <form v-else class="space-y-3" autocomplete="off" @submit.prevent="save">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label :class="lbl" for="dbx-email-account">{{ t('databox.email.account') }}</label>
            <select id="dbx-email-account" v-model="accountId" :class="input">
              <option value="">{{ t('databox.email.account_none') }}</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.username }})</option>
            </select>
            <p v-if="accounts.length === 0" class="text-xs text-warning-600 mt-1">
              {{ t('databox.email.no_accounts') }}
              <router-link to="/bank?tab=email" class="underline">{{ t('databox.email.go_accounts') }}</router-link>
            </p>
          </div>
          <div>
            <label :class="lbl" for="dbx-email-folder">{{ t('databox.email.folder') }}</label>
            <input id="dbx-email-folder" v-model="folder" type="text" maxlength="190" required :class="input" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('databox.email.folder_hint') }}</p>
          </div>
          <div>
            <label :class="lbl" for="dbx-email-alert">{{ t('databox.email.alert') }} {{ t('common.optional') }}</label>
            <input id="dbx-email-alert" v-model="alertEmail" type="email" maxlength="255" autocomplete="off" :class="input" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('databox.email.alert_hint') }}</p>
          </div>
        </div>

        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input v-model="enabled" type="checkbox" /> {{ t('databox.email.enabled') }}
        </label>

        <p class="text-xs text-neutral-500" data-test="email-last-scan">
          <template v-if="saved?.last_scan_at">
            {{ t('databox.email.last_scan', { date: formatDateTime(saved.last_scan_at) }) }}
            <span :class="saved.last_scan_status === 'ok' ? 'text-success-600' : 'text-danger-500'">· {{ t(`databox.email.scan_status.${saved.last_scan_status}`) }}</span>
            <span v-if="saved.last_scan_message"> · {{ saved.last_scan_message }}</span>
          </template>
          <template v-else>{{ t('databox.email.never_scanned') }}</template>
        </p>

        <div v-if="summary" class="text-xs rounded px-2 py-1 border border-neutral-200 bg-neutral-50" data-test="email-scan-summary">
          {{ t('databox.email.summary', {
            fetched: summary.fetched, created: summary.created, known: summary.known_skipped, ignored: summary.ignored,
            rejected: summary.rejected, unknown: summary.unknown_box, failed: summary.parse_failed, alerts: summary.alerts_sent,
          }) }}
          <span v-if="summary.status === 'error' && summary.message" class="block text-danger-500">{{ summary.message }}</span>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="submit" :disabled="busy || !dirty" class="cursor-pointer h-8 px-3 rounded-md bg-primary-600 text-white text-xs hover:bg-primary-700 disabled:opacity-50">
            {{ t('common.save') }}
          </button>
          <button type="button" :class="btn" :disabled="busy || accountIdOrNull === null" data-test="email-scan-now" @click="scan">{{ t('databox.email.scan_now') }}</button>
        </div>
      </form>
    </div>
  </details>
</template>
