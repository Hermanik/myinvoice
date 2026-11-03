<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

// Credentials only live in these local refs; the password is wiped after every submit attempt.
defineProps<{ busy: boolean; initialUsername?: string }>()
const emit = defineEmits<{
  (e: 'submit', value: { username: string; password: string }): void
  (e: 'cancel'): void
}>()
const { t } = useI18n()
const username = ref('')
const password = ref('')

function submit() {
  emit('submit', { username: username.value.trim(), password: password.value })
  password.value = ''
}
</script>

<template>
  <form class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end mt-2" autocomplete="off" @submit.prevent="submit">
    <div>
      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('databox.field.username') }}</label>
      <input v-model="username" type="text" required autocomplete="off" :placeholder="initialUsername"
        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
    </div>
    <div>
      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('databox.field.password') }}</label>
      <input v-model="password" type="password" required autocomplete="new-password"
        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
    </div>
    <div class="flex gap-2">
      <button type="submit" :disabled="busy || !username || !password"
        class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50">
        {{ t('common.save') }}
      </button>
      <button type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('cancel')">
        {{ t('common.cancel') }}
      </button>
    </div>
  </form>
</template>
