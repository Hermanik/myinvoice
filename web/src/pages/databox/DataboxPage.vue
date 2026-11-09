<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { databoxApi, type DataboxBox, type DataboxMessage, type MessageDetail } from '@/api/databox'
import { useToast } from '@/composables/useToast'
import { boxTitle, databoxErrorMessage, formatDateTime, stateBadgeClass } from '@/utils/databox'
import MessageDetailPanel from '@/components/databox/MessageDetail.vue'
import ComposeModal from '@/components/databox/ComposeModal.vue'

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const PER_PAGE = 25
const boxes = ref<DataboxBox[]>([])
const boxesLoaded = ref(false)
const watching = ref(false)
// null = the messages of every box in one list (default when there is more than one box).
const boxId = ref<number | null>(null)
const direction = ref<'received' | 'sent'>('received')
const unread = ref(false)
const taxOffice = ref(false)
const q = ref('')
const page = ref(1)
const items = ref<DataboxMessage[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref('')
const selectedId = ref<number | null>(null)
const composing = ref(false)

const currentBox = computed(() => boxes.value.find(b => b.id === boxId.value) ?? null)
// A box watched by notification e-mails has no login, so nothing can be sent from it.
const sendableBoxes = computed(() => boxes.value.filter(b => b.connection_mode !== 'email'))
const pages = computed(() => Math.max(1, Math.ceil(total.value / PER_PAGE)))

async function loadMessages() {
  if (boxes.value.length === 0) return
  loading.value = true
  error.value = ''
  try {
    const r = await databoxApi.listMessages(boxId.value, {
      direction: direction.value, unread: unread.value, tax_office: taxOffice.value,
      q: q.value.trim(), page: page.value, per_page: PER_PAGE,
    })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  } finally {
    loading.value = false
  }
}

function syncQuery() {
  router.replace({
    query: {
      box: boxId.value !== null ? String(boxId.value) : undefined,
      filter: taxOffice.value ? 'tax_office' : unread.value ? 'unread' : undefined,
      message: selectedId.value !== null ? String(selectedId.value) : undefined,
    },
  })
}

function resetAndLoad() {
  selectedId.value = null
  syncQuery()
  // A page change is loaded by its own watcher; only reload here when the page does not change.
  if (page.value !== 1) page.value = 1
  else void loadMessages()
}

function applyRouteQuery() {
  const b = Number(route.query.box)
  const active = boxes.value
  boxId.value = active.find(x => x.id === b)?.id ?? (active.length > 1 ? null : active[0]?.id ?? null)
  const f = route.query.filter
  taxOffice.value = f === 'tax_office'
  unread.value = f === 'unread'
  const m = Number(route.query.message)
  selectedId.value = Number.isFinite(m) && m > 0 ? m : null
}

onMounted(async () => {
  try {
    boxes.value = await databoxApi.listBoxes()
  } catch (e) {
    error.value = databoxErrorMessage(e, t, te)
  }
  boxesLoaded.value = true
  applyRouteQuery()
  await loadMessages()
  // Armed only now: the refs set by applyRouteQuery() must not trigger a second identical request.
  watching.value = true
})

watch([direction, unread, taxOffice, boxId], () => { if (watching.value) resetAndLoad() })
watch(page, () => { if (watching.value) void loadMessages() })

// Links from the dashboard / send result change the query while the page is already open.
watch(() => route.query, async () => {
  if (!watching.value || route.path !== '/databox') return
  const sameBox = String(boxId.value ?? '') === String(route.query.box ?? '')
  const sameMsg = String(selectedId.value ?? '') === String(route.query.message ?? '')
  const f = route.query.filter
  const sameFilter = taxOffice.value === (f === 'tax_office') && unread.value === (f === 'unread')
  if (sameBox && sameMsg && sameFilter) return
  // Same disarm / load / re-arm sequence as on mount: one request, and the linked message stays selected.
  watching.value = false
  applyRouteQuery()
  await loadMessages()
  watching.value = true
})

function select(m: DataboxMessage) {
  selectedId.value = m.id
  syncQuery()
}

// Keep the list row in sync with what the detail panel just changed (state, read marker).
function onChanged(d: MessageDetail) {
  const i = items.value.findIndex(x => x.id === d.id)
  if (i >= 0) items.value[i] = { ...items.value[i], ...d }
}

async function acknowledgeAll() {
  if (!window.confirm(t('databox.ack_all_confirm'))) return
  try {
    const r = await databoxApi.acknowledgeAll(boxId.value)
    toast.success(t('databox.ack_all_done', { count: r.acknowledged }))
    await loadMessages()
  } catch (e) {
    toast.error(databoxErrorMessage(e, t, te))
  }
}

const boxLabel = (id: number) => {
  const b = boxes.value.find(x => x.id === id)
  return b ? boxTitle(b) : ''
}
const isUnread = (m: DataboxMessage) => m.direction === 'received' && !m.acknowledged_at
const party = (m: DataboxMessage) => (m.direction === 'received' ? m.sender_name : m.recipient_name) || '—'
const chip = 'h-8 px-3 rounded-md border text-xs cursor-pointer'
</script>

<template>
  <div class="max-w-7xl">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('databox.page.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('databox.page.subtitle') }}</p>
      </div>
      <button v-if="sendableBoxes.some(b => b.is_active)" type="button" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700" @click="composing = true">
        {{ t('databox.compose.button') }}
      </button>
    </div>

    <div v-if="boxesLoaded && boxes.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
      {{ t('databox.page.no_boxes') }}
      <router-link to="/admin/settings#databox" class="text-primary-700 hover:underline">{{ t('databox.page.go_settings') }}</router-link>
    </div>

    <template v-else-if="boxesLoaded">
      <div class="flex flex-wrap items-center gap-3 mb-3">
        <select v-model="boxId" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" :aria-label="t('databox.page.box')">
          <option v-if="boxes.length > 1" :value="null">{{ t('databox.page.all_boxes') }}</option>
          <option v-for="b in boxes" :key="b.id" :value="b.id">{{ boxTitle(b) }} ({{ b.db_type }} · {{ b.db_id }}){{ b.is_active ? '' : ' – ' + t('common.inactive') }}</option>
        </select>
        <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button v-for="d in (['received', 'sent'] as const)" :key="d" type="button" class="cursor-pointer px-4 h-9"
            :class="direction === d ? 'bg-primary-600 text-white' : 'bg-surface hover:bg-neutral-50'" @click="direction = d">
            {{ t(`databox.tab.${d}`) }}
          </button>
        </div>
        <button type="button" :class="[chip, unread ? 'bg-primary-50 border-primary-200 text-primary-700' : 'border-neutral-300']" @click="unread = !unread">{{ t('databox.filter.unread') }}</button>
        <button type="button" :class="[chip, taxOffice ? 'bg-danger-50 border-danger-500/40 text-danger-500' : 'border-neutral-300']" @click="taxOffice = !taxOffice">{{ t('databox.filter.tax_office') }}</button>
        <input v-model="q" type="search" :placeholder="t('databox.filter.search')" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          @keydown.enter="resetAndLoad" />
        <button v-if="direction === 'received'" type="button" :class="[chip, 'border-neutral-300 ml-auto']" @click="acknowledgeAll">{{ t('databox.ack_all') }}</button>
      </div>

      <p v-if="currentBox && !currentBox.is_active" class="text-sm rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-2 mb-3">{{ t('databox.page.box_inactive') }}</p>
      <p v-if="error" class="text-sm rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2 mb-3" role="alert">{{ error }}</p>

      <div class="grid gap-4" :class="selectedId ? 'lg:grid-cols-[1fr_28rem]' : ''">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden self-start">
          <p v-if="loading" class="p-6 text-center text-sm text-neutral-400">{{ t('common.loading') }}</p>
          <p v-else-if="items.length === 0" class="p-6 text-center text-sm text-neutral-500">{{ t('databox.page.empty') }}</p>
          <ul v-else class="divide-y divide-neutral-100">
            <li v-for="m in items" :key="m.id">
              <button type="button" class="cursor-pointer w-full text-left px-4 py-2.5 hover:bg-neutral-50 flex items-start gap-3"
                :class="{ 'bg-primary-50/50': selectedId === m.id }" @click="select(m)">
                <span class="mt-1.5 w-2 h-2 rounded-full shrink-0" :class="isUnread(m) ? 'bg-primary-600' : 'bg-transparent'" :title="isUnread(m) ? t('databox.unread') : ''"></span>
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-2">
                    <span class="font-medium truncate" :class="{ 'font-semibold': isUnread(m) }">{{ party(m) }}</span>
                    <span v-if="boxId === null && boxLabel(m.databox_id)" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 border border-neutral-200" data-test="box-chip">{{ boxLabel(m.databox_id) }}</span>
                    <span v-if="m.is_tax_office" class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 border border-danger-500/40">{{ t('databox.tax_office') }}</span>
                    <span v-if="m.personal_delivery" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 border border-neutral-200">{{ t('databox.personal_delivery') }}</span>
                    <span v-if="m.source === 'email'" class="text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 border border-primary-200" data-test="source-email-badge">{{ t('databox.source.email') }}</span>
                  </span>
                  <span class="block text-sm text-neutral-700 truncate">{{ m.annotation || t('databox.detail.no_subject') }}</span>
                </span>
                <span class="text-right shrink-0">
                  <span class="block text-xs text-neutral-500">{{ formatDateTime(m.delivery_time) }}</span>
                  <span class="inline-block mt-0.5 text-xs px-2 py-0.5 border rounded-full" :class="stateBadgeClass(m.state)">{{ t(`databox.state.${m.state}`) }}</span>
                </span>
              </button>
            </li>
          </ul>
          <div v-if="total > PER_PAGE" class="flex items-center justify-between px-4 py-2 border-t border-neutral-100 text-xs text-neutral-500">
            <button type="button" class="cursor-pointer disabled:opacity-40" :disabled="page <= 1" @click="page--">{{ t('common.previous') }}</button>
            <span>{{ t('common.page') }} {{ page }} / {{ pages }} · {{ total }}</span>
            <button type="button" class="cursor-pointer disabled:opacity-40" :disabled="page >= pages" @click="page++">{{ t('common.next') }}</button>
          </div>
        </div>

        <MessageDetailPanel v-if="selectedId" :message-id="selectedId" @close="selectedId = null; syncQuery()" @changed="onChanged" />
      </div>
    </template>

    <ComposeModal v-if="composing" :boxes="sendableBoxes" :initial-box-id="boxId" @close="composing = false" @sent="loadMessages" />
  </div>
</template>
