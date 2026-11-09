<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { databoxApi, type DataboxBox } from '@/api/databox'
import { useToast } from '@/composables/useToast'
import { databoxErrorMessage } from '@/utils/databox'
import BoxCard from '@/components/databox/BoxCard.vue'
import AddBoxForm from '@/components/databox/AddBoxForm.vue'
import IsdsHelp from '@/components/databox/IsdsHelp.vue'
import EmailNoticeSettings from '@/components/databox/EmailNoticeSettings.vue'

const { t, te } = useI18n()
const toast = useToast()

const boxes = ref<DataboxBox[] | null>(null)
const adding = ref(false)

async function load() {
  try {
    boxes.value = await databoxApi.listBoxes()
  } catch (e) {
    boxes.value = []
    toast.error(databoxErrorMessage(e, t, te))
  }
  // Arriving via /admin/settings#databox: scroll once the section has its final height.
  if (window.location.hash === '#databox') {
    await nextTick()
    document.getElementById('databox')?.scrollIntoView()
  }
}

function replace(box: DataboxBox) {
  boxes.value = (boxes.value ?? []).map(b => (b.id === box.id ? box : b))
}

function onCreated(box: DataboxBox) {
  boxes.value = [...(boxes.value ?? []), box]
}

onMounted(load)
</script>

<template>
  <section id="databox" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm scroll-mt-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('databox.settings.title') }}</h2>
        <p class="text-xs text-neutral-500 mt-1">{{ t('databox.settings.hint') }}</p>
      </div>
      <button v-if="!adding" type="button" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700" @click="adding = true">
        {{ t('databox.add_box') }}
      </button>
    </div>

    <div class="mt-4 space-y-4">
      <IsdsHelp />
      <EmailNoticeSettings />
      <AddBoxForm v-if="adding" @created="onCreated" @cancel="adding = false" />
      <div v-if="boxes === null" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <p v-else-if="boxes.length === 0 && !adding" class="text-sm text-neutral-500">{{ t('databox.settings.empty') }}</p>
      <BoxCard v-for="b in boxes ?? []" :key="b.id" :box="b" @updated="replace" @removed="id => (boxes = (boxes ?? []).filter(x => x.id !== id))" />
    </div>
  </section>
</template>
