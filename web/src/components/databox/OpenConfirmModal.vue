<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { formatDate } from '@/composables/useFormat'

// Explicit confirmation before opening a message that is not delivered yet (opening delivers it).
defineProps<{ undelivered: boolean; fictionDate: string | null; busy: boolean }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'confirm'): void }>()
const { t } = useI18n()
const understood = ref(false)
</script>

<template>
  <Modal :title="t('databox.open.title')" width-class="max-w-lg" @close="emit('close')">
    <div class="space-y-3 text-sm">
      <p v-if="undelivered" class="rounded-md border border-danger-500/40 bg-danger-50 text-danger-500 px-3 py-2 font-medium" data-test="open-warning">
        {{ fictionDate ? t('databox.open.warning_undelivered', { date: formatDate(fictionDate) }) : t('databox.open.warning_undelivered_nodate') }}
      </p>
      <p v-else class="rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-2" data-test="open-notice">
        {{ t('databox.open.notice_fiction') }}
      </p>
      <label class="flex items-center gap-2 cursor-pointer">
        <input v-model="understood" type="checkbox" />
        <span>{{ t('databox.open.understand') }}</span>
      </label>
    </div>
    <template #footer>
      <button type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" :disabled="!understood || busy"
        class="cursor-pointer h-9 px-4 rounded-md bg-danger-500 text-white text-sm font-medium hover:bg-danger-600 disabled:opacity-50" @click="emit('confirm')">
        {{ busy ? t('common.loading') : t('databox.open.confirm') }}
      </button>
    </template>
  </Modal>
</template>
