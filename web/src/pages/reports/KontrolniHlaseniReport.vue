<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { reportsApi, type DphSettings, type KhFormParams } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { periodFromQuery } from '@/utils/reportPeriod'
import { isoToEpoDate } from '@/utils/epoDate'
import VatPrecheckPanels from '@/components/reports/VatPrecheckPanels.vue'

const { t, locale } = useI18n()

// The accountant's dashboard links here with ?year=&month= (the last closed period); otherwise the current month.
const route = useRoute()
const initialPeriod = periodFromQuery(route.query)
const year = ref(initialPeriod.year)
const month = ref(initialPeriod.month)

const settings = ref<DphSettings | null>(null)
const periodOverride = ref<'monthly' | 'quarterly' | ''>('')

// PO musí vždy měsíčně (§ 101e/1); FO může kvartálně (§ 101e/2)
const isLegalEntity = computed(() => settings.value?.taxpayer_type === 'po')
const effectivePeriod = computed<'monthly' | 'quarterly'>(() => {
  if (isLegalEntity.value) return 'monthly'
  if (periodOverride.value) return periodOverride.value
  return (settings.value?.vat_period as 'monthly' | 'quarterly') || 'monthly'
})
const currentQuarter = computed(() => Math.ceil(month.value / 3))

function setQuarter(q: number) {
  month.value = q * 3
}

const preview = ref<Awaited<ReturnType<typeof reportsApi.khPreview>> | null>(null)
const loading = ref(false)
const error = ref('')
// Chybějící povinná pole EPO identifikace (BUG 6) — chodí v těle náhledu.
// Náhled se kvůli nim NEBLOKUJE, jen se nad ním vykreslí výzva a zakáže se
// stažení XML (které by portál stejně odmítl).
const epoMissing = ref<Array<{ field: string; label: string; why: string }> | null>(null)

type KhSectionRow = {
  code: string
  labelKey: string
  count: number
  aggregated: boolean
}

const sectionARows = computed<KhSectionRow[]>(() => preview.value ? [
  { code: 'A.1', labelKey: 'reports.kh.a1_label', count: preview.value.summary.a1_count, aggregated: false },
  { code: 'A.2', labelKey: 'reports.kh.a2_label', count: preview.value.summary.a2_count, aggregated: false },
  { code: 'A.4', labelKey: 'reports.kh.a4_label', count: preview.value.summary.a4_count, aggregated: false },
  { code: 'A.5', labelKey: 'reports.kh.a5_label', count: preview.value.summary.a5_count_aggregated, aggregated: true },
] : [])

const sectionBRows = computed<KhSectionRow[]>(() => preview.value ? [
  { code: 'B.1', labelKey: 'reports.kh.b1_label', count: preview.value.summary.b1_count, aggregated: false },
  { code: 'B.2', labelKey: 'reports.kh.b2_label', count: preview.value.summary.b2_count, aggregated: false },
  { code: 'B.3', labelKey: 'reports.kh.b3_label', count: preview.value.summary.b3_count_aggregated, aggregated: true },
] : [])

// Form of the statement: B regular / O corrective (section 101f/1) / N follow-up (section 101f/2).
// A follow-up needs the date of discovery (d_zjist) — or the reference of the call of the tax office.
const form = ref<'B' | 'O' | 'N'>('B')
const dZjist = ref('') // ISO (YYYY-MM-DD) from <input type="date">

// Answer to a call of the tax office (vyzva): a full follow-up KH, or a quick answer without sections A/B/C.
type CallMode = 'none' | 'change' | 'confirm' | 'nil'
const callMode = ref<CallMode>('none')
const cJedVyzvy = ref('')
const CALL_REFERENCE_PATTERN = /^\d{1,8}\/\d{2}\/\d{4}-\d{5}-\d{6}$/
const callActive = computed(() => callMode.value !== 'none')
const callValid = computed(() => CALL_REFERENCE_PATTERN.test(cJedVyzvy.value.trim()))
const effForm = computed<'B' | 'O' | 'N'>(() => callMode.value === 'change' ? 'N' : callActive.value ? 'B' : form.value)
// The date of discovery only applies to the regular forms and to the follow-up answer.
const effDZjist = computed(() => {
  if (callActive.value ? callMode.value !== 'change' : form.value === 'B') return ''
  return dZjist.value ? isoToEpoDate(dZjist.value) : ''
})
const formParams = computed<KhFormParams>(() => ({
  form: effForm.value,
  dZjist: effDZjist.value,
  ...(callActive.value
    ? {
        cJedVyzvy: cJedVyzvy.value.trim(),
        ...(callMode.value === 'confirm' ? { vyzvaOdp: 'P' as const } : callMode.value === 'nil' ? { vyzvaOdp: 'B' as const } : {}),
      }
    : {}),
}))
// The discovery date is only sent with the follow-up answer to a call; only then does it affect the preview.
const previewDZjist = computed(() => callMode.value === 'change' ? effDZjist.value : '')
const dZjistRequired = computed(() => !callActive.value && form.value === 'N')
// A quick answer (vyzva_odp B/P) carries the call and is archived as form V.
const isQuickAnswer = computed(() => preview.value?.summary.call?.answer != null)

// `silent` refreshes keep the page content (and the precheck panels) mounted instead of showing the spinner.
async function loadPreview(silent = false) {
  if (!silent) loading.value = true
  error.value = ''
  epoMissing.value = null
  try {
    if (callActive.value && !callValid.value) {
      preview.value = null
      return
    }
    // Without a call the preview does not depend on the form (the follow-up fields only matter for the XML).
    preview.value = await reportsApi.khPreview(year.value, month.value, effectivePeriod.value, callActive.value ? formParams.value : {})
    epoMissing.value = preview.value.missing ?? null
  } catch (e) {
    epoMissing.value = null
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  window.open(reportsApi.khDownloadUrl(year.value, month.value, effectivePeriod.value, formParams.value), '_blank')
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
// Distinct roky z dat (issue #33).
const yearOptions = useYearOptions('combined', year)

const daysToDeadline = computed(() => {
  if (!preview.value?.summary.submission_deadline) return null
  const d = new Date(preview.value.summary.submission_deadline)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.ceil((d.getTime() - today.getTime()) / (1000 * 60 * 60 * 24))
})

watch([year, month, effectivePeriod], () => loadPreview())
watch([callMode, cJedVyzvy, previewDZjist], () => loadPreview(true))
onMounted(async () => {
  try { settings.value = await reportsApi.dphSettings() } catch {}
  loadPreview()
})
</script>

<template>
  <div class="max-w-5xl">
    <!-- ⚠️ Disclaimer -->
    <div class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-6 h-6 text-danger-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-1-8a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V6a1 1 0 0 0-1-1z" clip-rule="evenodd"/>
        </svg>
        <div class="text-sm text-danger-700">
          <p class="font-semibold mb-1">{{ t('reports.disclaimer_title') }}</p>
          <p>{{ t('reports.disclaimer_body') }}</p>
        </div>
      </div>
    </div>

    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.kh.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.kh.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Period toggle — pouze pro FO (PO musí vždy měsíčně) -->
        <div v-if="!isLegalEntity" class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="periodOverride = 'monthly'"
            :class="effectivePeriod === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="cursor-pointer px-3 h-9">{{ t('reports.dph.monthly') }}</button>
          <button type="button" @click="periodOverride = 'quarterly'"
            :class="effectivePeriod === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="cursor-pointer px-3 h-9 border-l border-neutral-300">{{ t('reports.dph.quarterly') }}</button>
        </div>
        <!-- Quarter selector (quarterly) nebo month selector (monthly) -->
        <template v-if="effectivePeriod === 'quarterly'">
          <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
          <select :value="currentQuarter" @change="setQuarter(Number(($event.target as HTMLSelectElement).value))"
            class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
          </select>
        </template>
        <template v-else>
          <select v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
          </select>
          <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </template>
        <!-- Forma hlášení (řádné/opravné/následné) + datum zjištění důvodů u následného -->
        <select v-if="!callActive" v-model="form" :title="t('reports.form.label')"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="B">{{ t('reports.form.b') }}</option>
          <option value="O">{{ t('reports.form.o') }}</option>
          <option value="N">{{ t('reports.form.n') }}</option>
        </select>
        <label v-if="!callActive && form !== 'B'" class="inline-flex items-center gap-1.5 text-sm text-neutral-600">
          <span>{{ t('reports.form.d_zjist') }}<span v-if="dZjistRequired" class="text-danger-500">*</span></span>
          <input v-model="dZjist" type="date"
            class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
        </label>
        <!-- Stažení XML blokujeme jen při chybějících POVINNÝCH polích identifikace —
             download vrací 422 a window.open by otevřel záložku se syrovým JSON. -->
        <button type="button" @click="downloadXml" :disabled="loading || !preview || (dZjistRequired && !dZjist) || !!epoMissing?.length"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.kh.download_xml') }}
        </button>
      </div>
    </div>

    <!-- Nekompletní EPO identifikace — kreslí se NAD náhledem, náhled zůstává
         viditelný (blokované je jen stažení XML, které by portál odmítl). -->
    <div v-if="epoMissing?.length" class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4">
      <p class="font-semibold text-danger-700 mb-1">{{ t('reports.epo.incomplete_title') }}</p>
      <p class="text-sm text-danger-700 mb-2">{{ t('reports.epo.incomplete_body') }}</p>
      <ul class="text-sm text-danger-700 list-disc list-inside space-y-0.5">
        <li v-for="m in epoMissing" :key="m.field"><strong>{{ m.label }}</strong> — {{ m.why }}</li>
      </ul>
      <RouterLink to="/admin/settings#epo" class="inline-flex items-center gap-1 mt-3 text-sm font-medium text-primary-700 hover:underline">
        {{ t('reports.epo.open_settings') }} →
      </RouterLink>
    </div>

    <!-- Answer to a call of the tax office (vyzva) -->
    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4" data-testid="kh-call">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('reports.kh.call.title') }}</h2>
      <div class="mt-3 grid grid-cols-1 md:grid-cols-[18rem_1fr_auto] gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('reports.kh.call.answer') }}</label>
          <select v-model="callMode" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="none">{{ t('reports.kh.call.mode_none') }}</option>
            <option value="change">{{ t('reports.kh.call.mode_change') }}</option>
            <option value="confirm">{{ t('reports.kh.call.mode_confirm') }}</option>
            <option value="nil">{{ t('reports.kh.call.mode_nil') }}</option>
          </select>
        </div>
        <div v-if="callActive">
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('reports.kh.call.reference') }}<span class="text-danger-500">*</span></label>
          <input v-model.trim="cJedVyzvy" type="text" maxlength="40" placeholder="1234567/26/2100-23542-501001"
            class="w-full h-9 px-3 border rounded-md text-sm bg-surface font-mono"
            :class="cJedVyzvy && !callValid ? 'border-danger-500' : 'border-neutral-300'" />
          <p class="text-xs mt-1" :class="cJedVyzvy && !callValid ? 'text-danger-500' : 'text-neutral-500'">{{ t('reports.kh.call.reference_hint') }}</p>
        </div>
        <div v-if="callMode === 'change'">
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('reports.form.d_zjist') }}</label>
          <input v-model="dZjist" type="date" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
      </div>
      <p v-if="callActive" class="text-xs text-neutral-500 mt-3">{{ t('reports.kh.call.deadline_note') }}</p>
      <p v-if="callMode === 'confirm' || callMode === 'nil'" class="text-xs text-neutral-500 mt-1">{{ t('reports.kh.call.no_sections_note') }}</p>
    </section>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <div v-else-if="callActive && !callValid" class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
      {{ t('reports.kh.call.fill_in') }}
    </div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- Kontrola souladu DPH ↔ KH ↔ SH, doklady změněné po podání -->
      <VatPrecheckPanels form="dphkh1" :year="year" :month="month" :period="effectivePeriod" />

      <!-- Deadline card -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.deadline') }}</div>
        <div class="text-xl font-bold font-mono"
          :class="(daysToDeadline ?? 999) < 0 ? 'text-danger-500' : (daysToDeadline ?? 999) <= 7 ? 'text-warning-600' : 'text-neutral-900'">
          {{ preview.summary.submission_deadline }}
        </div>
        <div class="text-xs mt-1"
          :class="(daysToDeadline ?? 999) < 0 ? 'text-danger-500' : (daysToDeadline ?? 999) <= 7 ? 'text-warning-600' : 'text-neutral-500'">
          <template v-if="daysToDeadline !== null && daysToDeadline >= 0">{{ t('reports.dph.deadline_in', { n: daysToDeadline }) }}</template>
          <template v-else-if="daysToDeadline !== null">{{ t('reports.dph.deadline_passed', { n: Math.abs(daysToDeadline) }) }}</template>
        </div>
      </div>

      <div v-if="isQuickAnswer && preview.summary.call" class="bg-surface border-2 border-primary-500 rounded-lg shadow-sm p-4 text-sm" data-testid="kh-quick-answer">
        <strong>{{ preview.summary.call.answer === 'P' ? t('reports.kh.call.mode_confirm') : t('reports.kh.call.mode_nil') }}</strong>
        · <span class="font-mono">{{ preview.summary.call.reference }}</span>
        <p class="text-xs text-neutral-500 mt-1">{{ t('reports.kh.call.no_sections_note') }}</p>
      </div>

      <!-- Sekce A — plnění s povinností přiznat daň -->
      <div v-if="!isQuickAnswer" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.kh.section_a_title') }}</h3>
        </header>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="section in sectionARows" :key="section.code">
              <td class="px-5 py-2.5 text-neutral-700">
                <strong class="font-mono">{{ section.code }}</strong> — {{ t(section.labelKey) }}
              </td>
              <td class="px-5 py-2.5 text-right font-mono">
                {{ section.count }} {{ t(section.aggregated ? 'reports.kh.aggregated' : 'reports.kh.rows') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Sekce B — přijaté -->
      <div v-if="!isQuickAnswer" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.kh.section_b_title') }}</h3>
        </header>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="section in sectionBRows" :key="section.code">
              <td class="px-5 py-2.5 text-neutral-700">
                <strong class="font-mono">{{ section.code }}</strong> — {{ t(section.labelKey) }}
              </td>
              <td class="px-5 py-2.5 text-right font-mono">
                {{ section.count }} {{ t(section.aggregated ? 'reports.kh.aggregated' : 'reports.kh.rows') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tip -->
      <div class="bg-primary-50 border border-primary-200 rounded-md p-3 text-sm text-primary-700">
        💡 {{ isLegalEntity ? t('reports.kh.note_po') : t('reports.kh.note_fo') }}
      </div>
    </div>
  </div>
</template>
