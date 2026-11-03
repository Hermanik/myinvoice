<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type VatStatusOverview, type VatStatusRow } from '@/api/settings'
import type { VatStatus } from '@/utils/vatStatus'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'

const { t } = useI18n()
const toast = useToast()

// The parent refreshes the supplier store from the overview after every change.
const emit = defineEmits<{ (event: 'changed', overview: VatStatusOverview): void }>()

const overview = ref<VatStatusOverview | null>(null)
const saving = ref(false)

const STATUSES: VatStatus[] = ['payer', 'non_payer', 'identified']
const form = ref<{ valid_from: string; status: VatStatus; note: string }>({
  valid_from: '',
  status: 'payer',
  note: '',
})

const statusLabel = (status: VatStatus) => t(`settings.vat_status.status_${status}`)
const statusBadgeClass = (status: VatStatus) => ({
  payer: 'bg-success-50 text-success-600 border-success-500/40',
  identified: 'bg-primary-50 text-primary-700 border-primary-200',
  non_payer: 'bg-neutral-100 text-neutral-700 border-neutral-200',
}[status])

const currentRow = computed<VatStatusRow | null>(() => overview.value?.history.find(r => r.is_current) ?? null)

async function load() {
  overview.value = await settingsApi.getVatStatusHistory()
}

function apply(next: VatStatusOverview) {
  overview.value = next
  emit('changed', next)
}

async function add() {
  if (!form.value.valid_from) {
    toast.error(t('settings.vat_status.date_required'))
    return
  }
  saving.value = true
  try {
    apply(await settingsApi.addVatStatus({
      valid_from: form.value.valid_from,
      status: form.value.status,
      note: form.value.note.trim() || null,
    }))
    form.value = { valid_from: '', status: form.value.status, note: '' }
    toast.success(t('settings.vat_status.added'))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function remove(row: VatStatusRow) {
  if (!window.confirm(t('settings.vat_status.delete_confirm', { status: statusLabel(row.status), date: formatDate(row.valid_from) }))) return
  try {
    apply(await settingsApi.deleteVatStatus(row.id))
    toast.success(t('settings.vat_status.deleted'))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

onMounted(load)
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.vat_status.title') }}</h2>
    <p class="text-xs text-neutral-500 mt-1">{{ t('settings.vat_status.hint') }}</p>

    <div v-if="overview" class="mt-4 space-y-4">
      <!-- Current status + the next scheduled change -->
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span class="text-sm text-neutral-600">{{ t('settings.vat_status.today') }}:</span>
        <span class="inline-block px-2.5 py-0.5 text-sm font-medium border rounded-full" :class="statusBadgeClass(overview.status)">
          {{ statusLabel(overview.status) }}
        </span>
        <span v-if="currentRow" class="text-xs text-neutral-500">{{ t('settings.vat_status.since', { date: formatDate(currentRow.valid_from) }) }}</span>
        <span v-if="overview.next_change" class="text-xs text-neutral-600">
          · {{ t('settings.vat_status.next_change', { date: formatDate(overview.next_change.valid_from), status: statusLabel(overview.next_change.status) }) }}
        </span>
      </div>

      <p v-if="overview.status === 'identified'" class="text-xs text-neutral-500">{{ t('settings.vat_status.identified_hint') }}</p>
      <p v-if="overview.flat_tax_conflict_year" class="text-sm rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-2">
        {{ t('settings.vat_status.flat_tax_conflict', { year: overview.flat_tax_conflict_year }) }}
      </p>

      <!-- History -->
      <div class="overflow-x-auto border border-neutral-200 rounded-md">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.vat_status.col_from') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.vat_status.col_status') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.vat_status.col_note') }}</th>
              <th class="px-3 py-2 w-28"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in overview.history" :key="row.id" class="hover:bg-neutral-50">
              <td class="px-3 py-2 whitespace-nowrap">
                {{ formatDate(row.valid_from) }}
                <span v-if="row.is_future" class="ml-2 inline-block px-1.5 py-0.5 text-xs rounded bg-warning-50 text-warning-600 border border-warning-500/40">{{ t('settings.vat_status.badge_future') }}</span>
                <span v-else-if="row.is_current" class="ml-2 inline-block px-1.5 py-0.5 text-xs rounded bg-success-50 text-success-600 border border-success-500/40">{{ t('settings.vat_status.badge_current') }}</span>
              </td>
              <td class="px-3 py-2">
                <span class="inline-block px-2 py-0.5 text-xs border rounded-full" :class="statusBadgeClass(row.status)">{{ statusLabel(row.status) }}</span>
              </td>
              <td class="px-3 py-2 text-neutral-600">{{ row.note || '—' }}</td>
              <td class="px-3 py-2 text-right">
                <button v-if="row.can_delete" type="button" class="cursor-pointer text-xs text-danger-600 hover:text-danger-500" @click="remove(row)">
                  {{ t('settings.vat_status.delete') }}
                </button>
                <span v-else class="text-xs text-neutral-400 cursor-help" :title="t('settings.vat_status.delete_earliest_hint')">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Add a change -->
      <form class="grid grid-cols-1 md:grid-cols-[10rem_14rem_1fr_auto] gap-3 items-end" @submit.prevent="add">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_status.field_from') }}</label>
          <input v-model="form.valid_from" type="date" min="2000-01-01" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_status.field_status') }}</label>
          <select v-model="form.status" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="s in STATUSES" :key="s" :value="s">{{ statusLabel(s) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_status.field_note') }}</label>
          <input v-model="form.note" type="text" maxlength="255" :placeholder="t('settings.vat_status.note_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <button type="submit" :disabled="saving"
          class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50">
          {{ t('settings.vat_status.add') }}
        </button>
      </form>
    </div>
    <div v-else class="mt-4 text-sm text-neutral-500">{{ t('common.loading') }}</div>
  </section>
</template>
