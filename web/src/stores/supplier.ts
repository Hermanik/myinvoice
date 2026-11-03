import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import type { SupplierBrief } from '@/api/auth'
import { vatFlagsAt as resolveVatFlagsAt, type VatFlags } from '@/utils/vatStatus'

const STORAGE_KEY = 'myinvoice.current_supplier_id'

/**
 * Multi-supplier scope na frontendu.
 * - `currentSupplierId` se persistuje v localStorage
 * - axios interceptor (api/client.ts) z něj plní hlavičku `X-Supplier-Id`
 * - po /me se naplní `availableSuppliers` (pro switcher v hlavičce)
 *
 * Po přepnutí volej `reloadAfterSwitch()` (router push na /, který re-fetchne).
 */
export const useSupplierStore = defineStore('supplier', () => {
  const initial = (() => {
    const v = localStorage.getItem(STORAGE_KEY)
    return v && /^\d+$/.test(v) ? parseInt(v, 10) : 0
  })()

  const currentSupplierId = ref<number>(initial)
  const availableSuppliers = ref<SupplierBrief[]>([])

  const hasMultiple = computed(() => availableSuppliers.value.length > 1)
  // False = onboarding přeskočil dodavatele (žádný supplier v DB). Bez dodavatele je
  // celá aplikace nepoužitelná (data jsou supplier-scoped, currencies se sázejí per-supplier),
  // proto router + dashboard navigují uživatele na vytvoření prvního dodavatele.
  const hasSupplier = computed(() => availableSuppliers.value.length > 0)
  const currentSupplier = computed<SupplierBrief | null>(() =>
    availableSuppliers.value.find(s => s.id === currentSupplierId.value) ?? null,
  )

  watch(currentSupplierId, (v) => {
    if (v > 0) localStorage.setItem(STORAGE_KEY, String(v))
    else localStorage.removeItem(STORAGE_KEY)
  })

  /** VAT flags of the current supplier on a date (an invoice's DUZP) — from the dated history sent with /me. */
  function vatFlagsAt(date: string): VatFlags {
    return resolveVatFlagsAt(currentSupplier.value?.vat_history, date)
  }

  function setSupplier(id: number) {
    currentSupplierId.value = id
  }

  function setAvailable(list: SupplierBrief[], serverCurrent: number) {
    availableSuppliers.value = list
    // Pokud localStorage value není v dostupném listu, přejdi na server-side default
    if (!list.find(s => s.id === currentSupplierId.value)) {
      currentSupplierId.value = serverCurrent || (list[0]?.id ?? 0)
    }
  }

  /**
   * Propsání změn z uloženého nastavení dodavatele do brief listu (issue #94).
   * availableSuppliers se plní jen z /me při startu — bez patche by editor
   * faktur četl stale hodnoty (is_vat_payer, defaulty) až do hard refreshe.
   */
  function patchSupplier(id: number, partial: Partial<SupplierBrief>) {
    const idx = availableSuppliers.value.findIndex(s => s.id === id)
    if (idx === -1) return
    availableSuppliers.value[idx] = { ...availableSuppliers.value[idx], ...partial, id }
  }

  return {
    currentSupplierId,
    availableSuppliers,
    hasMultiple,
    hasSupplier,
    currentSupplier,
    vatFlagsAt,
    setSupplier,
    setAvailable,
    patchSupplier,
  }
})
