<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { databoxApi, type ZfoImportResult, type ZfoOutcome } from '@/api/databox'
import { formatBytes } from '@/components/documents/docFormat'
import { databoxErrorMessage } from '@/utils/databox'

const emit = defineEmits<{ (e: 'close'): void; (e: 'imported'): void }>()
const { t, te } = useI18n()

// The same limits the server enforces (a single file / files per request); bigger files are refused there with a reason.
const MAX_BYTES = 25 * 1024 * 1024
const MAX_FILES = 50

const files = ref<File[]>([])
const results = ref<ZfoImportResult[]>([])
const dragging = ref(false)
const busy = ref(false)
const finished = ref(false)
const picker = ref<HTMLInputElement | null>(null)

const tooMany = computed(() => files.value.length > MAX_FILES)
const canUpload = computed(() => !busy.value && files.value.length > 0 && !tooMany.value)
const counts = computed(() => {
  const c: Record<ZfoOutcome, number> = { created: 0, updated: 0, duplicate: 0, rejected: 0 }
  for (const r of results.value) c[r.outcome]++
  return c
})

// The same file picked twice is one file.
const keyOf = (f: File) => `${f.name}|${f.size}|${f.lastModified}`

function add(list: FileList | File[] | null | undefined) {
  if (!list || busy.value) return
  finished.value = false
  for (const f of Array.from(list)) {
    if (!files.value.some(x => keyOf(x) === keyOf(f))) files.value.push(f)
  }
}

function onPick(ev: Event) {
  const input = ev.target as HTMLInputElement
  add(input.files)
  input.value = ''
}

function onDrop(ev: DragEvent) {
  dragging.value = false
  add(ev.dataTransfer?.files)
}

function failed(name: string, reason: string): ZfoImportResult {
  return { name, outcome: 'rejected', reason, kind: null, dm_id: null, direction: null, databox_id: null, message_id: null, signer: null, filing: null }
}

// One request per file: a single upload stays far below the server's request size limit, and one bad file never hides the others.
async function upload() {
  if (!canUpload.value) return
  busy.value = true
  results.value = []
  for (const f of files.value) {
    if (f.size > MAX_BYTES) {
      results.value.push(failed(f.name, t('databox.zfo.too_big')))
      continue
    }
    try {
      const fd = new FormData()
      fd.append('files[]', f)
      results.value.push(...(await databoxApi.importZfo(fd)).results)
    } catch (e) {
      results.value.push(failed(f.name, databoxErrorMessage(e, t, te)))
    }
  }
  files.value = []
  busy.value = false
  finished.value = true
  if (counts.value.created + counts.value.updated > 0) emit('imported')
}

const outcomeClass = (o: ZfoOutcome) => ({
  created: 'bg-success-50 text-success-600 border-success-500/40',
  updated: 'bg-primary-50 text-primary-700 border-primary-200',
  duplicate: 'bg-neutral-100 text-neutral-600 border-neutral-200',
  rejected: 'bg-danger-50 text-danger-500 border-danger-500/40',
}[o])
</script>

<template>
  <Modal :title="t('databox.zfo.title')" width-class="max-w-2xl" @close="emit('close')">
    <div class="space-y-3 text-sm">
      <div class="rounded-md border border-primary-200 bg-primary-50 text-primary-700 px-3 py-2 space-y-1.5" data-test="zfo-help">
        <p>{{ t('databox.zfo.help_where') }}</p>
        <p>{{ t('databox.zfo.help_scope') }}</p>
        <p>{{ t('databox.zfo.help_delivers') }}</p>
        <p class="text-xs">{{ t('databox.zfo.help_integrity') }}</p>
      </div>

      <div class="rounded-lg border-2 border-dashed px-4 py-6 text-center cursor-pointer transition-colors"
        :class="dragging ? 'border-primary-600 bg-primary-50' : 'border-neutral-300 hover:bg-neutral-50'" data-test="zfo-dropzone"
        role="button" tabindex="0" :aria-label="t('databox.zfo.drop')"
        @click="picker?.click()" @keydown.enter.prevent="picker?.click()" @keydown.space.prevent="picker?.click()"
        @dragenter.prevent="dragging = true" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop">
        <p class="font-medium">{{ t('databox.zfo.drop') }}</p>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('databox.zfo.drop_hint', { max: formatBytes(MAX_BYTES) }) }}</p>
        <input ref="picker" type="file" multiple accept=".zfo,application/octet-stream" class="hidden" data-test="zfo-picker" @change="onPick" />
      </div>

      <p v-if="tooMany" class="text-xs text-danger-500" role="alert">{{ t('databox.zfo.too_many', { max: MAX_FILES }) }}</p>

      <ul v-if="files.length" class="text-xs divide-y divide-neutral-100 border border-neutral-200 rounded-md" data-test="zfo-queue">
        <li v-for="(f, i) in files" :key="keyOf(f)" class="flex justify-between gap-2 px-3 py-1">
          <span class="truncate">{{ f.name }} <span class="text-neutral-500">({{ formatBytes(f.size) }})</span></span>
          <button v-if="!busy" type="button" class="cursor-pointer text-danger-500 shrink-0" @click="files.splice(i, 1)">{{ t('common.remove') }}</button>
        </li>
      </ul>

      <div v-if="results.length" class="space-y-2" data-test="zfo-results">
        <h3 class="font-medium">{{ t('databox.zfo.results') }}</h3>
        <ul class="space-y-1.5">
          <li v-for="(r, i) in results" :key="i" class="rounded-md border border-neutral-200 px-3 py-2" data-test="zfo-result">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-xs px-2 py-0.5 border rounded-full" :class="outcomeClass(r.outcome)">{{ t(`databox.zfo.outcome.${r.outcome}`) }}</span>
              <span v-if="r.kind" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 border border-neutral-200">{{ t(`databox.zfo.kind.${r.kind}`) }}</span>
              <span class="font-medium truncate">{{ r.name }}</span>
            </div>
            <p class="text-xs mt-1" :class="r.outcome === 'rejected' ? 'text-danger-500' : 'text-neutral-700'">{{ r.reason }}</p>
            <p v-if="r.signer" class="text-xs text-neutral-500 mt-0.5">{{ t('databox.zfo.signer', { signer: r.signer }) }}</p>
          </li>
        </ul>
        <p v-if="finished" class="text-xs text-neutral-600" data-test="zfo-summary">
          {{ t('databox.zfo.summary', { created: counts.created, updated: counts.updated, duplicate: counts.duplicate, rejected: counts.rejected }) }}
        </p>
      </div>
    </div>
    <template #footer>
      <button type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('close')">{{ t('common.close') }}</button>
      <button type="button" :disabled="!canUpload" data-test="zfo-upload"
        class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" @click="upload">
        {{ busy ? t('databox.zfo.uploading') : t('databox.zfo.upload') }}
      </button>
    </template>
  </Modal>
</template>
