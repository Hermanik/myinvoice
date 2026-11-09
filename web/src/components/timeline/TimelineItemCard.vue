<script setup lang="ts">
/**
 * One item of the obligations timeline: date block, title, status, what to do, notes, amount, legal basis,
 * links into the application and — where the application cannot tell on its own — the "Hotovo" checkbox
 * with a note. Texts come from the i18n catalogue by the item's `type`; the server sends codes and parameters.
 */
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { TaxYearEstimate, TimelineCategory, TimelineItem, TimelineNote, TimelineStatus } from '@/api/timeline'
import TimelineEstimate from '@/components/timeline/TimelineEstimate.vue'
import { formatDate, formatMonth, formatMoney } from '@/composables/useFormat'
import { coveredByOrder, dateFormatters, formatParams, safeLink, textParams as buildTextParams } from '@/utils/timeline'

const props = defineProps<{ item: TimelineItem; busy: boolean }>()
// The events carry the item, so the parent can bind one stable handler for all cards instead of a closure per card.
const emit = defineEmits<{
  (e: 'toggle', item: TimelineItem, done: boolean): void
  (e: 'note', item: TimelineItem, note: string): void
  (e: 'edit', item: TimelineItem): void
  (e: 'remove', item: TimelineItem): void
}>()

const { t, te, locale } = useI18n()

const BADGE: Record<TimelineStatus, string> = {
  done: 'bg-success-50 text-success-600',
  overdue: 'bg-danger-50 text-danger-500',
  due_soon: 'bg-warning-50 text-warning-600',
  upcoming: 'bg-neutral-100 text-neutral-600',
  info: 'bg-primary-50 text-primary-700',
  unverified: 'bg-neutral-100 text-neutral-500',
}
const DOT: Record<TimelineCategory, string> = {
  registration: 'bg-danger-500',
  dph: 'bg-primary-600',
  income_tax: 'bg-warning-500',
  pausal: 'bg-neutral-500',
  insurance: 'bg-success-500',
  invoicing: 'bg-primary-300',
  custom: 'bg-neutral-400',
}
const CHIP: Record<TimelineCategory, string> = {
  registration: 'bg-danger-50 text-danger-500',
  dph: 'bg-primary-50 text-primary-700',
  income_tax: 'bg-warning-50 text-warning-600',
  pausal: 'bg-neutral-100 text-neutral-600',
  insurance: 'bg-success-50 text-success-600',
  invoicing: 'bg-neutral-100 text-neutral-600',
  custom: 'bg-neutral-100 text-neutral-600',
}

/** Notes that only inform; the others ask the owner to verify or act and are highlighted. */
const INFO_NOTES = new Set([
  'standing_order_scheduled', 'standing_order_overpaid', 'estimate_basis', 'estimate_basis_closed', 'estimate_overpaid',
  'advance_from_statement', 'invoicing_vat_payer',
])

const formatters = {
  date: formatDate,
  money: (n: number) => formatMoney(n, 'CZK', 0),
  month: formatMonth,
  quarter: (q: number, year: number) => t('timeline.quarter_label', { q, year }),
  claim: (code: string) => t(`timeline.claim.${code}`),
}
/** Parameters of the catalogue texts: the item's own, formatted for display, plus the derived ones. */
const textParams = computed(() => buildTextParams(props.item, formatters))

const custom = computed(() => props.item.custom ?? null)
const title = computed(() => custom.value?.title ?? t(`timeline.item.${props.item.type}.title`, textParams.value))
const what = computed(() => {
  if (custom.value) return custom.value.note ?? ''
  const variant = props.item.params.reason ?? props.item.params.frequency ?? props.item.params.rule
  const specific = `timeline.item.${props.item.type}.what_${variant}`
  const key = variant && te(specific) ? specific : `timeline.item.${props.item.type}.what`
  return te(key) ? t(key, textParams.value) : ''
})
const noteText = (n: TimelineNote) => t(`timeline.note.${n.code}`, { ...textParams.value, ...formatParams(n.params, formatters) })
const link = computed(() => safeLink(custom.value?.url))

const dateParts = computed(() => {
  const d = new Date(`${props.item.due_date}T00:00:00Z`)
  const f = dateFormatters(locale.value)
  const day = d.getUTCDate()
  const month = f.month.format(d)
  return { label: locale.value === 'en' ? `${month} ${day}` : `${day}. ${month}`, weekday: f.weekday.format(d), year: d.getUTCFullYear() }
})
const daysText = computed(() => {
  const n = props.item.days_left
  return n > 0 ? t('timeline.in_days', { n }) : n === 0 ? t('timeline.today') : t('timeline.overdue_days', { n: Math.abs(n) })
})
const DAYS_CLASS: Partial<Record<TimelineStatus, string>> = {
  overdue: 'text-danger-500 font-medium',
  due_soon: 'text-warning-600 font-medium',
}
const daysClass = computed(() => DAYS_CLASS[props.item.status] ?? 'text-neutral-500')

const isDone = computed(() => props.item.status === 'done')
const autoDone = computed(() => props.item.done_source === 'auto')
const paidByOrder = computed(() => props.item.done_source === 'standing_order')
/** A standing order covers the item (paid or still to pay): there is nothing for the owner to tick, unless already ticked. */
const ordered = computed(() => coveredByOrder(props.item))
const paidOn = computed(() => {
  const ymd = props.item.params.paid_on
  return typeof ymd === 'string' ? dateFormatters(locale.value).dayMonth.format(new Date(`${ymd}T00:00:00Z`)) : ''
})
/** The estimate of the year, on the card that announces the lost flat-tax regime. */
const estimate = computed(() => (props.item.type === 'flat_tax_lost' ? (props.item.params.estimate as TaxYearEstimate | undefined) : undefined))
const markInfo = computed(() => {
  const m = props.item.mark
  return m && m.done && m.by ? t('timeline.done_by', { by: m.by, at: formatDate(m.at) }) : ''
})

const editingNote = ref(false)
const noteDraft = ref('')
function openNote() {
  noteDraft.value = props.item.mark?.note ?? ''
  editingNote.value = true
}
function saveNote() {
  emit('note', props.item, noteDraft.value.trim())
  editingNote.value = false
}
</script>

<template>
  <li class="relative pl-6" data-testid="timeline-item" :data-key="item.key" :data-status="item.status" :data-category="item.category">
    <span class="absolute -left-[7px] top-5 w-3.5 h-3.5 rounded-full ring-4 ring-neutral-50" :class="DOT[item.category]" aria-hidden="true"></span>
    <article
      class="bg-surface border rounded-lg shadow-sm p-4 flex flex-col sm:flex-row gap-3 sm:gap-4"
      :class="[
        item.severity === 'warning' && !isDone ? 'border-warning-500/60' : 'border-neutral-200',
        isDone ? 'opacity-70' : '',
      ]"
    >
      <!-- Date -->
      <div class="sm:w-24 shrink-0 flex sm:block items-baseline gap-2">
        <div class="text-xs uppercase tracking-wide text-neutral-500 first-letter:uppercase">{{ dateParts.weekday }}</div>
        <div class="text-2xl font-semibold leading-none tabular-nums" data-testid="timeline-day">{{ dateParts.label }}</div>
        <div class="text-xs text-neutral-500">{{ dateParts.year }}</div>
        <div v-if="!isDone" class="text-xs sm:mt-1" :class="daysClass">{{ daysText }}</div>
      </div>

      <div class="min-w-0 flex-1 space-y-2">
        <div class="flex items-start gap-2 flex-wrap">
          <h3 class="font-medium text-neutral-900 break-words" :class="isDone ? 'line-through decoration-neutral-400' : ''">{{ title }}</h3>
          <span class="px-2 py-0.5 rounded text-xs font-medium shrink-0" :class="BADGE[item.status]" data-testid="timeline-badge">{{ t('timeline.badge_' + item.status) }}</span>
          <span class="px-2 py-0.5 rounded text-xs shrink-0" :class="CHIP[item.category]">{{ t('timeline.category.' + item.category) }}</span>
          <span v-if="item.obligation === 'recommended' && !custom" class="px-2 py-0.5 rounded text-xs shrink-0 border border-neutral-200 text-neutral-500">{{ t('timeline.recommended') }}</span>
        </div>

        <p v-if="what" class="text-sm text-neutral-700 whitespace-pre-line" data-testid="timeline-what"><span v-if="!custom" class="font-medium text-neutral-900">{{ t('timeline.what_label') }}: </span>{{ what }}</p>

        <ul v-if="item.notes.length" class="space-y-0.5">
          <li v-for="n in item.notes" :key="n.code" class="text-xs" :class="INFO_NOTES.has(n.code) ? 'text-neutral-600' : 'text-warning-600'" :data-note="n.code">{{ noteText(n) }}</li>
        </ul>

        <TimelineEstimate v-if="estimate" :estimate="estimate" />

        <div class="flex items-center gap-x-4 gap-y-1 flex-wrap text-xs text-neutral-500">
          <span v-if="item.amount !== null" class="font-mono text-sm text-neutral-800" data-testid="timeline-amount">
            {{ formatMoney(item.amount, 'CZK', 0) }}<span v-if="item.amount_estimated" class="font-sans text-xs text-neutral-500"> ({{ t('timeline.estimate') }})</span>
          </span>
          <span v-if="item.start_date && item.start_date !== item.due_date">{{ t('timeline.window_from', { date: formatDate(item.start_date) }) }}</span>
          <span v-if="item.legal" :title="t('timeline.legal_label')">{{ item.legal }}</span>
        </div>

        <div class="flex items-center gap-x-4 gap-y-2 flex-wrap">
          <RouterLink v-for="l in item.links" :key="l.to" :to="l.to" class="text-sm text-primary-700 hover:underline">{{ t('timeline.link.' + l.label) }} →</RouterLink>
          <template v-if="link">
            <RouterLink v-if="link.kind === 'internal'" :to="link.href" class="text-sm text-primary-700 hover:underline">{{ t('timeline.custom_open_link') }} →</RouterLink>
            <a v-else :href="link.href" target="_blank" rel="noopener noreferrer" class="text-sm text-primary-700 hover:underline">{{ t('timeline.custom_open_link') }} ↗</a>
          </template>
        </div>

        <div class="flex items-center gap-x-4 gap-y-2 flex-wrap pt-1 border-t border-neutral-100">
          <span v-if="autoDone" class="text-xs text-success-600" data-testid="timeline-done-auto">✓ {{ t('timeline.done_auto') }}</span>
          <span v-else-if="paidByOrder" class="text-xs text-success-600" data-testid="timeline-done-order">✓ {{ t('timeline.paid_by_order', { date: paidOn }) }}</span>
          <label v-else-if="!ordered || isDone" class="inline-flex items-center gap-2 text-sm cursor-pointer select-none" :title="item.tracked ? '' : t('timeline.mark_hint')">
            <input type="checkbox" :checked="isDone" :disabled="busy" class="w-4 h-4 accent-success-600"
              data-testid="timeline-done-checkbox" @change="emit('toggle', item, ($event.target as HTMLInputElement).checked)" />
            {{ t('timeline.mark_done') }}
          </label>
          <span v-if="item.done_source === 'manual' && markInfo" class="text-xs text-neutral-500">{{ t('timeline.done_manual') }} · {{ markInfo }}</span>

          <button v-if="!custom" type="button" class="cursor-pointer text-xs text-primary-700 hover:underline" @click="openNote">{{ t('timeline.add_note') }}</button>
          <span v-if="item.mark?.note && !editingNote" class="text-xs text-neutral-600 italic" data-testid="timeline-note-text">„{{ item.mark.note }}“</span>

          <span v-if="custom" class="ml-auto inline-flex items-center gap-3">
            <button type="button" class="cursor-pointer text-xs text-primary-700 hover:underline" data-testid="timeline-edit" @click="emit('edit', item)">{{ t('common.edit') }}</button>
            <button type="button" class="cursor-pointer text-xs text-danger-600 hover:text-danger-500" data-testid="timeline-remove" @click="emit('remove', item)">{{ t('common.delete') }}</button>
          </span>
        </div>

        <form v-if="editingNote" class="flex items-center gap-2" @submit.prevent="saveNote">
          <input v-model="noteDraft" type="text" maxlength="500" :placeholder="t('timeline.note_placeholder')"
            class="flex-1 h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="timeline-note-input" />
          <button type="submit" :disabled="busy" class="cursor-pointer h-8 px-3 rounded-md bg-primary-600 text-white text-xs hover:bg-primary-700 disabled:opacity-50">{{ t('timeline.note_save') }}</button>
          <button type="button" class="cursor-pointer h-8 px-2 text-xs text-neutral-500 hover:text-neutral-800" @click="editingNote = false">{{ t('common.cancel') }}</button>
        </form>
      </div>
    </article>
  </li>
</template>
