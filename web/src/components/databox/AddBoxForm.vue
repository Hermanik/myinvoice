<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { databoxApi, type DataboxBox, type DataboxEnvironment } from '@/api/databox'
import { useToast } from '@/composables/useToast'
import { databoxErrorMessage } from '@/utils/databox'
import PermissionIndicator from './PermissionIndicator.vue'

const emit = defineEmits<{ (e: 'created', box: DataboxBox): void; (e: 'cancel'): void }>()
const { t, te } = useI18n()
const toast = useToast()

const busy = ref(false)
const created = ref<DataboxBox | null>(null)
const label = ref('')
const environment = ref<DataboxEnvironment>('production')
const syncUser = ref('')
const syncPass = ref('')
const useAction = ref(false)
const actionUser = ref('')
const actionPass = ref('')
const allowDelivering = ref(false)

// Passwords are only held in these refs and wiped after every attempt, successful or not.
function wipe() {
  syncPass.value = ''
  actionPass.value = ''
}

async function submit() {
  if (allowDelivering.value && !window.confirm(t('databox.delivering.confirm'))) return
  busy.value = true
  try {
    const box = await databoxApi.createBox({
      label: label.value.trim() || undefined,
      environment: environment.value,
      sync: { username: syncUser.value.trim(), password: syncPass.value },
      action: useAction.value && actionUser.value.trim() ? { username: actionUser.value.trim(), password: actionPass.value } : undefined,
      allow_delivering_sync: allowDelivering.value || undefined,
    })
    created.value = box
    emit('created', box)
    toast.success(t('databox.box_added'))
  } catch (e) {
    toast.error(databoxErrorMessage(e, t, te))
  } finally {
    wipe()
    busy.value = false
  }
}

const input = 'w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface'
const lbl = 'block text-xs font-medium text-neutral-700 mb-1'
</script>

<template>
  <div v-if="created" class="rounded-md border border-success-500/40 bg-success-50 p-4 space-y-2">
    <p class="text-sm font-medium text-success-600">{{ t('databox.box_resolved') }}</p>
    <p class="text-sm">
      <code class="bg-surface rounded px-1.5 py-0.5">{{ created.db_id }}</code>
      <span class="ml-2 text-xs px-2 py-0.5 border rounded-full bg-surface">{{ created.db_type }}</span>
      <span class="ml-2">{{ created.owner_name }}</span>
    </p>
    <PermissionIndicator :state="created.sync.state" />
    <button type="button" class="cursor-pointer h-8 px-3 rounded-md border border-neutral-300 text-xs bg-surface hover:bg-neutral-50" @click="emit('cancel')">{{ t('common.close') }}</button>
  </div>

  <form v-else class="border border-neutral-200 rounded-md p-4 space-y-4" autocomplete="off" @submit.prevent="submit">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label :class="lbl">{{ t('databox.field.label') }} {{ t('common.optional') }}</label>
        <input v-model="label" type="text" maxlength="100" :class="input" />
      </div>
      <div>
        <label :class="lbl">{{ t('databox.field.environment') }}</label>
        <select v-model="environment" :class="input">
          <option value="production">{{ t('databox.env.production') }}</option>
          <option value="test">{{ t('databox.env.test') }}</option>
        </select>
      </div>
    </div>

    <fieldset class="space-y-2">
      <legend class="text-sm font-medium">{{ t('databox.purpose.sync') }} ({{ t('common.required') }})</legend>
      <p class="text-xs text-neutral-500">{{ t('databox.add_sync_hint') }}</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label :class="lbl">{{ t('databox.field.username') }}</label>
          <input v-model="syncUser" type="text" required autocomplete="off" :class="input" />
        </div>
        <div>
          <label :class="lbl">{{ t('databox.field.password') }}</label>
          <input v-model="syncPass" type="password" required autocomplete="new-password" :class="input" />
        </div>
      </div>
    </fieldset>

    <fieldset class="space-y-2">
      <label class="flex items-center gap-2 text-sm font-medium cursor-pointer">
        <input v-model="useAction" type="checkbox" /> {{ t('databox.add_action_login') }} {{ t('common.optional') }}
      </label>
      <template v-if="useAction">
        <p class="text-xs text-neutral-500">{{ t('databox.add_action_hint') }}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label :class="lbl">{{ t('databox.field.username') }}</label>
            <input v-model="actionUser" type="text" autocomplete="off" :class="input" />
          </div>
          <div>
            <label :class="lbl">{{ t('databox.field.password') }}</label>
            <input v-model="actionPass" type="password" autocomplete="new-password" :class="input" />
          </div>
        </div>
      </template>
    </fieldset>

    <div class="rounded-md border border-neutral-200 px-3 py-2">
      <label class="flex items-start gap-2 text-sm cursor-pointer">
        <input v-model="allowDelivering" type="checkbox" class="mt-1" />
        <span>
          <span class="font-medium">{{ t('databox.delivering.label') }}</span>
          <span class="block text-xs text-danger-500">{{ t('databox.delivering.warning') }}</span>
        </span>
      </label>
    </div>

    <div class="flex gap-2">
      <button type="submit" :disabled="busy || !syncUser || !syncPass"
        class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50">
        {{ busy ? t('databox.connecting') : t('databox.add_box_submit') }}
      </button>
      <button type="button" class="cursor-pointer h-9 px-3 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="emit('cancel')">{{ t('common.cancel') }}</button>
    </div>
  </form>
</template>
