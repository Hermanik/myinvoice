<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { databoxApi, type DataboxBoxSummary, type LookupBox, type PrepareResponse } from '@/api/databox'
import { formatBytes } from '@/components/documents/docFormat'
import { boxTitle, databoxErrorMessage } from '@/utils/databox'
import SendConfirmModal from './SendConfirmModal.vue'

const props = defineProps<{ boxes: DataboxBoxSummary[]; initialBoxId: number | null }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'sent'): void }>()
const { t, te } = useI18n()

const MAX_BYTES = 20 * 1024 * 1024

const activeBoxes = computed(() => props.boxes.filter(b => b.is_active))
const boxId = ref<number | null>(activeBoxes.value.find(b => b.id === props.initialBoxId)?.id ?? activeBoxes.value[0]?.id ?? null)
const recipient = ref('')
const annotation = ref('')
const toHands = ref('')
const senderRef = ref('')
const personal = ref(false)
const files = ref<File[]>([])
const busy = ref(false)
const error = ref('')
const query = ref('')
const results = ref<LookupBox[] | null>(null)
const lookupBusy = ref(false)
const prepared = ref<PrepareResponse | null>(null)

const totalBytes = computed(() => files.value.reduce((s, f) => s + f.size, 0))
const tooBig = computed(() => totalBytes.value > MAX_BYTES)
const canPrepare = computed(() => !busy.value && boxId.value !== null && recipient.value.trim().length === 7
  && annotation.value.trim().length > 0 && files.value.length > 0 && !tooBig.value)

function onFiles(ev: Event) {
  const input = ev.target as HTMLInputElement
  files.value = [...files.value, ...Array.from(input.files ?? [])]
  input.value = ''
}

async function lookup() {
  if (boxId.value === null || !query.value.trim()) return
  lookupBusy.value = true
  error.value = ''
  try {
    results.value = (await databoxApi.lookup(boxId.value, query.value.trim())).boxes
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  } finally {
    lookupBusy.value = false
  }
}

function pick(b: LookupBox) {
  recipient.value = b.db_id
  results.value = null
}

// Prepare only validates and stores the draft; nothing is sent until the confirmation modal.
async function prepare() {
  if (!canPrepare.value || boxId.value === null) return
  busy.value = true
  error.value = ''
  try {
    const fd = new FormData()
    fd.append('box_id', String(boxId.value))
    fd.append('recipient_box_id', recipient.value.trim())
    fd.append('annotation', annotation.value.trim())
    if (toHands.value.trim()) fd.append('to_hands', toHands.value.trim())
    if (senderRef.value.trim()) fd.append('sender_ref_number', senderRef.value.trim())
    if (personal.value) fd.append('personal_delivery', '1')
    files.value.forEach(f => fd.append('files[]', f))
    prepared.value = await databoxApi.prepareMessage(fd)
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  } finally {
    busy.value = false
  }
}

const input = 'w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface'
const lbl = 'block text-xs font-medium text-neutral-700 mb-1'
</script>

<template>
  <SendConfirmModal v-if="prepared" :prepared="prepared" @close="prepared = null" @expired="prepared = null" @sent="emit('sent')" />
  <Modal v-else :title="t('databox.compose.title')" width-class="max-w-2xl" @close="emit('close')">
    <div class="space-y-3 text-sm">
      <div>
        <label :class="lbl">{{ t('databox.compose.from_box') }}</label>
        <select v-model="boxId" :class="input">
          <option v-for="b in activeBoxes" :key="b.id" :value="b.id">{{ boxTitle(b) }} ({{ b.db_type }} · {{ b.db_id }})</option>
        </select>
      </div>

      <div>
        <label :class="lbl">{{ t('databox.compose.recipient_id') }}</label>
        <input v-model="recipient" type="text" maxlength="7" :class="[input, 'font-mono']" />
        <div class="flex gap-2 mt-2">
          <input v-model="query" type="text" :placeholder="t('databox.compose.lookup_placeholder')" :class="input" @keydown.enter.prevent="lookup" />
          <button type="button" :disabled="lookupBusy" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50 shrink-0" @click="lookup">
            {{ t('databox.compose.lookup') }}
          </button>
        </div>
        <ul v-if="results" class="mt-2 border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-44 overflow-y-auto">
          <li v-if="!results.length" class="px-3 py-2 text-neutral-500">{{ t('databox.compose.no_results') }}</li>
          <li v-for="r in results" :key="r.db_id">
            <button type="button" class="cursor-pointer w-full text-left px-3 py-1.5 hover:bg-neutral-50" @click="pick(r)">
              {{ r.name }} <code class="text-xs">{{ r.db_id }}</code>
              <span v-if="r.is_tax_office" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 border border-danger-500/40">{{ t('databox.tax_office') }}</span>
            </button>
          </li>
        </ul>
      </div>

      <div>
        <label :class="lbl">{{ t('databox.field.annotation') }} <span class="text-neutral-400">({{ annotation.length }}/255)</span></label>
        <input v-model="annotation" type="text" maxlength="255" :class="input" />
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div><label :class="lbl">{{ t('databox.field.to_hands') }} {{ t('common.optional') }}</label><input v-model="toHands" type="text" maxlength="255" :class="input" /></div>
        <div><label :class="lbl">{{ t('databox.field.sender_ref_number') }} {{ t('common.optional') }}</label><input v-model="senderRef" type="text" maxlength="50" :class="input" /></div>
      </div>
      <label class="flex items-center gap-2 cursor-pointer"><input v-model="personal" type="checkbox" /> {{ t('databox.field.personal_delivery') }}</label>

      <div>
        <label :class="lbl">{{ t('databox.compose.files') }}</label>
        <input type="file" multiple class="text-sm" @change="onFiles" />
        <p class="text-xs mt-1" :class="tooBig ? 'text-danger-500' : 'text-neutral-500'">{{ t('databox.compose.files_hint', { size: formatBytes(totalBytes) }) }}</p>
        <ul v-if="files.length" class="mt-1 text-xs divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <li v-for="(f, i) in files" :key="i" class="flex justify-between px-3 py-1">
            <span>{{ f.name }} <span class="text-neutral-500">({{ formatBytes(f.size) }})</span></span>
            <button type="button" class="cursor-pointer text-danger-500" @click="files.splice(i, 1)">{{ t('common.remove') }}</button>
          </li>
        </ul>
      </div>
      <p v-if="error" class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2" role="alert">{{ error }}</p>
    </div>
    <template #footer>
      <button type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" :disabled="!canPrepare" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" @click="prepare">
        {{ busy ? t('common.loading') : t('databox.compose.prepare') }}
      </button>
    </template>
  </Modal>
</template>
