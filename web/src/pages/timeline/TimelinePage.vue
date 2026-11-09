<script setup lang="ts">
/**
 * Harmonogram: everything the owner has to (or should) do for taxes, insurance and invoicing over a date range,
 * newest deadline last, with what is done. One request per range change; the status and category filters work on
 * the loaded items. Manual marks and custom tasks are written through the API and patched into the list; the regular
 * payments (standing orders) and the timeline settings change what the server computes, so they reload it. Above the
 * list: the estimated surcharges of a year without the flat-tax regime.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  timelineApi,
  type Timeline,
  type TimelineCategory,
  type TimelineCustomInput,
  type TimelineItem,
} from '@/api/timeline'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import StandingOrdersModal from '@/components/timeline/StandingOrdersModal.vue'
import TimelineEstimate from '@/components/timeline/TimelineEstimate.vue'
import TimelineItemCard from '@/components/timeline/TimelineItemCard.vue'
import { formatMonth } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'
import {
  RANGE_PRESETS,
  TIMELINE_CATEGORIES,
  buildRows,
  countByCategory,
  filterItems,
  isYmd,
  matchingPreset,
  presetRange,
  statusOf,
  type RangePreset,
  type StatusFilter,
} from '@/utils/timeline'

const { t } = useI18n()
const toast = useToast()

const today = ref(appIsoDate())
const initial = presetRange('rest_and_next', today.value)
const from = ref(initial.from)
const to = ref(initial.to)
const activePreset = computed(() => matchingPreset(from.value, to.value, today.value))

const data = ref<Timeline | null>(null)
const loading = ref(true)
const error = ref('')
const busyKey = ref<string | null>(null)

const ordersOpen = ref(false)

const statusFilter = ref<StatusFilter>('all')
const categories = ref<TimelineCategory[]>([])

// ---- loading: one request per range change, the latest one wins ----
let seq = 0
// A date typed into the picker passes through odd years (0002-…) on the way: only a sane range is requested.
const sane = (d: string) => isYmd(d) && d >= '2000-01-01' && d <= '2100-12-31'

async function load(silent = false) {
  if (!sane(from.value) || !sane(to.value) || from.value > to.value) return
  const mine = ++seq
  if (!silent) loading.value = true
  error.value = ''
  try {
    const res = await timelineApi.get(from.value, to.value)
    if (mine !== seq) return
    data.value = res
    today.value = res.today
  } catch (e) {
    if (mine === seq) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (mine === seq) loading.value = false
  }
}

watch([from, to], () => { void load() })
onMounted(() => { void load() })

function applyPreset(p: RangePreset) {
  const r = presetRange(p, today.value)
  from.value = r.from
  to.value = r.to
}

// ---- derived view ----
const items = computed(() => data.value?.items ?? [])
const visible = computed(() => filterItems(items.value, statusFilter.value, categories.value))
const rows = computed(() => buildRows(visible.value, today.value, from.value, to.value))
const categoryCounts = computed(() => countByCategory(items.value))
// Counts of the status filters, from the loaded items (a mark changes them without another request).
const counts = computed<Record<StatusFilter, number>>(() => {
  const c = { all: items.value.length, todo: 0, done: 0, overdue: 0 }
  for (const i of items.value) {
    if (i.status === 'done') c.done++
    else c.todo++
    if (i.status === 'overdue') c.overdue++
  }
  return c
})

function toggleCategory(c: TimelineCategory) {
  categories.value = categories.value.includes(c) ? categories.value.filter(x => x !== c) : [...categories.value, c]
}

const STATUS_FILTERS: StatusFilter[] = ['all', 'todo', 'done', 'overdue']

// ---- marks (optimistic: the server's answer replaces the mark, the status follows locally) ----
function patchItem(key: string, patch: (i: TimelineItem) => void) {
  const item = data.value?.items.find(i => i.key === key)
  if (item) patch(item)
}

async function toggleDone(item: TimelineItem, done: boolean) {
  busyKey.value = item.key
  try {
    if (item.custom) {
      const saved = await timelineApi.updateCustom(item.custom.id, { done })
      patchItem(item.key, i => {
        i.done_source = saved.done_at ? 'manual' : null
        i.mark = saved.done_at ? { done: true, note: null, by: saved.done_by, at: saved.done_at } : null
        i.status = statusOf(i, saved.done_at !== null)
      })
    } else {
      await writeMark(item.key, done, item.mark?.note ?? null)
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, t('timeline.mark_failed')))
    await load(true)
  } finally {
    busyKey.value = null
  }
}

async function saveNote(item: TimelineItem, note: string) {
  busyKey.value = item.key
  try {
    await writeMark(item.key, item.mark?.done ?? false, note || null)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('timeline.mark_failed')))
  } finally {
    busyKey.value = null
  }
}

/** A mark with neither the "done" flag nor a note is just removed. */
async function writeMark(key: string, done: boolean, note: string | null) {
  const res = done || note ? await timelineApi.setMark(key, done, note) : await timelineApi.clearMark(key)
  applyMark(key, res.mark)
}

function applyMark(key: string, mark: TimelineItem['mark']) {
  patchItem(key, i => {
    i.mark = mark
    // Evidence wins over a mark (the application's own, or a standing order that paid the item): only an item without
    // any follows the mark.
    if (i.done_source !== 'auto' && i.done_source !== 'standing_order') {
      i.done_source = mark?.done ? 'manual' : null
      i.status = statusOf(i, mark?.done ?? false)
    }
  })
}

// ---- custom tasks ----
interface CustomForm {
  id: number | null
  title: string
  due_date: string
  start_date: string
  category: TimelineCategory
  note: string
  url: string
}
const form = ref<CustomForm | null>(null)
const saving = ref(false)
const formError = ref('')

function openAdd() {
  form.value = { id: null, title: '', due_date: '', start_date: '', category: 'custom', note: '', url: '' }
  formError.value = ''
}

function openEdit(item: TimelineItem) {
  const c = item.custom
  if (!c) return
  form.value = {
    id: c.id, title: c.title, due_date: item.due_date, start_date: item.start_date ?? '',
    category: item.category, note: c.note ?? '', url: c.url ?? '',
  }
  formError.value = ''
}

async function submitForm() {
  const f = form.value
  if (!f) return
  if (!f.title.trim() || !isYmd(f.due_date)) {
    formError.value = t('timeline.custom_required')
    return
  }
  const body: TimelineCustomInput = {
    title: f.title.trim(),
    due_date: f.due_date,
    start_date: f.start_date || null,
    category: f.category,
    note: f.note.trim() || null,
    url: f.url.trim() || null,
  }
  saving.value = true
  formError.value = ''
  try {
    if (f.id === null) await timelineApi.createCustom(body)
    else await timelineApi.updateCustom(f.id, body)
  } catch (e) {
    formError.value = apiErrorMessage(e, t('common.error'))
    return
  } finally {
    saving.value = false
  }
  form.value = null
  toast.success(t('timeline.custom_saved'))
  await load(true)
}

async function removeCustom(item: TimelineItem) {
  if (!item.custom || !window.confirm(t('timeline.custom_delete_confirm', { title: item.custom.title }))) return
  try {
    await timelineApi.removeCustom(item.custom.id)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    return
  }
  toast.success(t('timeline.custom_deleted'))
  await load(true)
}

</script>

<template>
  <div class="max-w-5xl space-y-4" data-testid="timeline-page">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold">{{ t('timeline.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('timeline.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <button type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 bg-surface text-sm text-neutral-700 hover:bg-neutral-50" data-testid="timeline-orders" @click="ordersOpen = true">
          {{ t('timeline.standing_orders') }}
        </button>
        <button type="button" class="cursor-pointer h-9 px-3 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700" data-testid="timeline-add" @click="openAdd">
          + {{ t('timeline.add_custom') }}
        </button>
      </div>
    </div>
    <p class="text-xs text-neutral-500">{{ t('timeline.intro') }}</p>

    <!-- Estimated surcharges of a year without the flat-tax regime: how much to reserve and by when -->
    <TimelineEstimate v-for="e in data?.estimates ?? []" :key="e.year" :estimate="e" compact />

    <!-- Range, presets and filters -->
    <section class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3" data-testid="timeline-filters">
      <div class="flex items-end gap-3 flex-wrap">
        <label class="text-xs font-medium text-neutral-700">
          {{ t('timeline.range_from') }}
          <input v-model="from" type="date" class="block mt-1 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-range-from" />
        </label>
        <label class="text-xs font-medium text-neutral-700">
          {{ t('timeline.range_to') }}
          <input v-model="to" type="date" class="block mt-1 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-range-to" />
        </label>
        <div class="flex gap-1.5 flex-wrap">
          <button v-for="p in RANGE_PRESETS" :key="p" type="button" :data-testid="'timeline-preset-' + p"
            class="cursor-pointer h-9 px-3 rounded-md border text-sm"
            :class="activePreset === p ? 'bg-primary-600 text-white border-primary-600' : 'bg-surface text-neutral-700 border-neutral-300 hover:bg-neutral-50'"
            @click="applyPreset(p)">
            {{ t('timeline.preset_' + p) }}
          </button>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs font-medium text-neutral-500 mr-1">{{ t('timeline.filter_status') }}</span>
        <button v-for="f in STATUS_FILTERS" :key="f" type="button" :data-testid="'timeline-status-' + f" :aria-pressed="statusFilter === f"
          class="cursor-pointer h-8 px-3 rounded-full border text-xs"
          :class="statusFilter === f ? 'bg-primary-50 text-primary-700 border-primary-300 font-medium' : 'bg-surface text-neutral-600 border-neutral-300 hover:bg-neutral-50'"
          @click="statusFilter = f">
          {{ t('timeline.status_' + f) }} <span class="text-neutral-400">{{ counts[f] }}</span>
        </button>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs font-medium text-neutral-500 mr-1">{{ t('timeline.filter_category') }}</span>
        <button v-for="c in TIMELINE_CATEGORIES" :key="c" type="button" :data-testid="'timeline-category-' + c" :aria-pressed="categories.includes(c)"
          class="cursor-pointer h-8 px-3 rounded-full border text-xs"
          :class="categories.includes(c) ? 'bg-primary-50 text-primary-700 border-primary-300 font-medium' : 'bg-surface text-neutral-600 border-neutral-300 hover:bg-neutral-50'"
          @click="toggleCategory(c)">
          {{ t('timeline.category.' + c) }} <span class="text-neutral-400">{{ categoryCounts[c] ?? 0 }}</span>
        </button>
      </div>

      <p v-if="data" class="text-xs text-neutral-500" data-testid="timeline-counts">
        {{ t('timeline.counts', { total: counts.all, todo: counts.todo, overdue: counts.overdue, done: counts.done }) }}
      </p>
    </section>

    <div v-if="loading && !data" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-400">{{ t('timeline.loading') }}</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm" data-testid="timeline-error">{{ error }}</div>

    <template v-else-if="data">
      <div v-if="items.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-600" data-testid="timeline-empty">
        {{ t('timeline.empty') }}
      </div>
      <div v-else-if="visible.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-600" data-testid="timeline-empty-filtered">
        {{ t('timeline.empty_filtered') }}
      </div>

      <ol v-else class="relative ml-2 border-l-2 border-neutral-200 space-y-3 pb-2" :class="loading ? 'opacity-60' : ''">
        <template v-for="row in rows" :key="row.kind === 'item' ? row.item.key : row.kind === 'month' ? 'm' + row.ym : 'today'">
          <li v-if="row.kind === 'month'" class="relative pl-6 pt-3" data-testid="timeline-month">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-600 first-letter:uppercase">{{ formatMonth(row.ym) }}</h2>
          </li>
          <li v-else-if="row.kind === 'today'" class="relative pl-6" data-testid="timeline-today">
            <span class="absolute -left-[7px] top-1/2 -translate-y-1/2 w-3.5 h-3.5 rounded-full bg-danger-500 ring-4 ring-neutral-50" aria-hidden="true"></span>
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded bg-danger-500 text-white text-xs font-semibold">{{ t('timeline.today_marker') }}</span>
              <span class="flex-1 border-t-2 border-danger-500/60"></span>
            </div>
          </li>
          <TimelineItemCard v-else :item="row.item" :busy="busyKey === row.item.key"
            @toggle="toggleDone" @note="saveNote" @edit="openEdit" @remove="removeCustom" />
        </template>
      </ol>
    </template>

    <StandingOrdersModal v-if="ordersOpen && data" :settings="data.settings" @close="ordersOpen = false" @changed="load(true)" />

    <!-- Custom task form -->
    <Modal v-if="form" :title="form.id === null ? t('timeline.add_custom') : t('timeline.edit_custom')" width-class="max-w-lg" @close="form = null">
      <form id="timeline-form" class="space-y-3" data-testid="timeline-form" @submit.prevent="submitForm">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.custom_title') }}</label>
          <input v-model="form.title" type="text" maxlength="255" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-form-title" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.custom_due') }}</label>
            <input v-model="form.due_date" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-form-due" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.custom_start') }}</label>
            <input v-model="form.start_date" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.custom_category') }}</label>
          <select v-model="form.category" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="c in TIMELINE_CATEGORIES" :key="c" :value="c">{{ t('timeline.category.' + c) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.custom_note') }}</label>
          <textarea v-model="form.note" rows="3" maxlength="1000" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('timeline.custom_url') }}</label>
          <input v-model="form.url" type="text" maxlength="500" placeholder="/clients" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <p v-if="formError" class="text-sm text-danger-500" data-testid="timeline-form-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="form = null">{{ t('common.cancel') }}</button>
        <button type="submit" form="timeline-form" :disabled="saving" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" data-testid="timeline-form-save">{{ t('common.save') }}</button>
      </template>
    </Modal>
  </div>
</template>
