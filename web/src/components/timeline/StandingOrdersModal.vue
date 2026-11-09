<script setup lang="ts">
/**
 * "Pravidelné platby": the standing orders the owner pays the advances by (the application cannot see the bank
 * account, so the timeline takes the items they cover as paid) and the settings of the timeline. Orders are
 * listed, added, edited and deleted here; every change tells the page to reload the timeline.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  timelineApi,
  type InvoicingDue,
  type StandingOrder,
  type StandingOrderInput,
  type StandingOrderType,
  type TimelineSettings,
} from '@/api/timeline'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'
import { isYmd, STANDING_ORDER_TYPES } from '@/utils/timeline'

const props = defineProps<{ settings: TimelineSettings }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'changed'): void
}>()

const { t } = useI18n()
const toast = useToast()

const orders = ref<StandingOrder[]>([])
const loading = ref(true)
const loadError = ref('')

async function loadOrders() {
  try {
    orders.value = await timelineApi.listOrders()
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(() => { void loadOrders() })

// ---- the order form ----
interface OrderForm {
  id: number | null
  type: StandingOrderType
  amount: string
  day_of_month: string
  valid_from: string
  valid_to: string
  note: string
}
const form = ref<OrderForm | null>(null)
const saving = ref(false)
const formError = ref('')

function openAdd() {
  form.value = { id: null, type: 'pausal', amount: '', day_of_month: '', valid_from: appIsoDate(), valid_to: '', note: '' }
  formError.value = ''
}

function openEdit(o: StandingOrder) {
  form.value = {
    id: o.id, type: o.type, amount: String(o.amount), day_of_month: String(o.day_of_month),
    valid_from: o.valid_from, valid_to: o.valid_to ?? '', note: o.note ?? '',
  }
  formError.value = ''
}

/** The body of the request, or null when the form is not valid. */
function orderBody(f: OrderForm): StandingOrderInput | null {
  const amount = Number(f.amount.replace(',', '.'))
  const day = Number(f.day_of_month)
  const validTo = f.valid_to || null
  if (!(amount > 0) || !Number.isInteger(day) || day < 1 || day > 31 || !isYmd(f.valid_from)) return null
  if (validTo !== null && (!isYmd(validTo) || validTo < f.valid_from)) return null
  return { type: f.type, amount, day_of_month: day, valid_from: f.valid_from, valid_to: validTo, note: f.note.trim() || null }
}

/** A change of the orders: say so, reload the list and the timeline (which they decide) at the same time. */
async function afterChange(message: string) {
  toast.success(message)
  emit('changed')
  await loadOrders()
}

async function submitOrder() {
  const f = form.value
  if (!f) return
  const body = orderBody(f)
  if (!body) {
    formError.value = t('timeline.order_required')
    return
  }
  saving.value = true
  formError.value = ''
  try {
    if (f.id === null) await timelineApi.createOrder(body)
    else await timelineApi.updateOrder(f.id, body)
  } catch (e) {
    formError.value = apiErrorMessage(e, t('common.error'))
    return
  } finally {
    saving.value = false
  }
  form.value = null
  await afterChange(t('timeline.order_saved'))
}

async function removeOrder(o: StandingOrder) {
  const type = t(`timeline.order_types.${o.type}`)
  if (!window.confirm(t('timeline.order_delete_confirm', { type, amount: formatMoney(o.amount, 'CZK', 0) }))) return
  try {
    await timelineApi.removeOrder(o.id)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    return
  }
  await afterChange(t('timeline.order_deleted'))
}

// ---- settings ----
const invoicingDue = ref<InvoicingDue>(props.settings.invoicing_due)
const workingDay = ref(String(props.settings.invoicing_working_day))
const settingsSaving = ref(false)
const settingsError = ref('')

async function saveSettings() {
  const day = Number(workingDay.value)
  if (!Number.isInteger(day) || day < 1 || day > 20) {
    settingsError.value = t('timeline.order_required')
    return
  }
  settingsSaving.value = true
  settingsError.value = ''
  try {
    await timelineApi.saveSettings({ invoicing_due: invoicingDue.value, invoicing_working_day: day })
  } catch (e) {
    settingsError.value = apiErrorMessage(e, t('common.error'))
    return
  } finally {
    settingsSaving.value = false
  }
  toast.success(t('timeline.settings_saved'))
  emit('changed')
}

const validity = (o: StandingOrder) => o.valid_to
  ? t('timeline.order_valid_range', { from: formatDate(o.valid_from), to: formatDate(o.valid_to) })
  : t('timeline.order_valid_from', { from: formatDate(o.valid_from) })
</script>

<template>
  <Modal :title="t('timeline.orders_title')" width-class="max-w-2xl" @close="emit('close')">
    <div class="space-y-6" data-testid="timeline-orders-modal">
      <section class="space-y-3">
        <p class="text-sm text-neutral-600">{{ t('timeline.orders_intro') }}</p>

        <p v-if="loading" class="text-sm text-neutral-400">{{ t('timeline.loading') }}</p>
        <p v-else-if="loadError" class="text-sm text-danger-500">{{ loadError }}</p>
        <p v-else-if="orders.length === 0" class="text-sm text-neutral-500" data-testid="timeline-orders-empty">{{ t('timeline.orders_empty') }}</p>
        <ul v-else class="divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <li v-for="o in orders" :key="o.id" class="px-3 py-2 flex items-center gap-3 flex-wrap" data-testid="timeline-order-row">
            <div class="min-w-0 flex-1">
              <div class="text-sm font-medium text-neutral-900">{{ t('timeline.order_types.' + o.type) }}</div>
              <div class="text-xs text-neutral-500">
                {{ t('timeline.order_day_value', { day: o.day_of_month }) }} · {{ validity(o) }}<template v-if="o.note"> · {{ o.note }}</template>
              </div>
            </div>
            <span class="font-mono text-sm text-neutral-800">{{ formatMoney(o.amount, 'CZK', 0) }}</span>
            <button type="button" class="cursor-pointer text-xs text-primary-700 hover:underline" data-testid="timeline-order-edit" @click="openEdit(o)">{{ t('common.edit') }}</button>
            <button type="button" class="cursor-pointer text-xs text-danger-600 hover:text-danger-500" data-testid="timeline-order-remove" @click="removeOrder(o)">{{ t('common.delete') }}</button>
          </li>
        </ul>

        <button v-if="!form" type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" data-testid="timeline-orders-add" @click="openAdd">
          + {{ t('timeline.order_add') }}
        </button>

        <form v-else id="timeline-order-form" class="space-y-3 border border-neutral-200 rounded-md p-3 bg-neutral-50" data-testid="timeline-order-form" @submit.prevent="submitOrder">
          <h3 class="text-sm font-semibold">{{ form.id === null ? t('timeline.order_add') : t('timeline.order_edit') }}</h3>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.order_type') }}</label>
            <select v-model="form.type" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-order-type">
              <option v-for="type in STANDING_ORDER_TYPES" :key="type" :value="type">{{ t('timeline.order_types.' + type) }}</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.order_amount') }}</label>
              <input v-model="form.amount" type="text" inputmode="decimal" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-order-amount" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.order_day') }}</label>
              <input v-model="form.day_of_month" type="number" min="1" max="31" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-order-day" />
            </div>
          </div>
          <p class="text-xs text-neutral-500 -mt-1">{{ t('timeline.order_day_hint') }}</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.order_from') }}</label>
              <input v-model="form.valid_from" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-order-from" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.order_to') }}</label>
              <input v-model="form.valid_to" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-order-to" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.order_note') }}</label>
            <input v-model="form.note" type="text" maxlength="255" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <p v-if="formError" class="text-sm text-danger-500" data-testid="timeline-order-error">{{ formError }}</p>
          <div class="flex items-center justify-end gap-2">
            <button type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-100" @click="form = null">{{ t('common.cancel') }}</button>
            <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" data-testid="timeline-order-save">{{ t('common.save') }}</button>
          </div>
        </form>
      </section>

      <section class="space-y-3 border-t border-neutral-200 pt-4" data-testid="timeline-settings">
        <h3 class="text-sm font-semibold">{{ t('timeline.settings_title') }}</h3>
        <div class="grid grid-cols-2 gap-3 items-end">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.settings_invoicing_due') }}</label>
            <select v-model="invoicingDue" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-settings-due">
              <option value="month_end">{{ t('timeline.settings_month_end') }}</option>
              <option value="working_day_n">{{ t('timeline.settings_working_day_n') }}</option>
            </select>
          </div>
          <div v-if="invoicingDue === 'working_day_n'">
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.settings_working_day') }}</label>
            <input v-model="workingDay" type="number" min="1" max="20" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-settings-day" />
          </div>
        </div>
        <p class="text-xs text-neutral-500">{{ t('timeline.settings_hint') }}</p>
        <p v-if="settingsError" class="text-sm text-danger-500">{{ settingsError }}</p>
        <button type="button" :disabled="settingsSaving" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" data-testid="timeline-settings-save" @click="saveSettings">{{ t('common.save') }}</button>
      </section>
    </div>
    <template #footer>
      <button type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('close')">{{ t('common.close') }}</button>
    </template>
  </Modal>
</template>
