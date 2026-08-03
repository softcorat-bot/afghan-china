import { defineBoot } from '#q-app'
import { Notify, Dialog } from 'quasar'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'
import { fmtDate, fmtDateTime } from '@/utils/date'

// Reusable components ported from the legacy app, registered globally under the
// same short aliases the old pages used so templates port over with minimal edits.
import AppHeader from '@/components/Headers/AppHeader.vue'
import AcButton from '@/components/Buttons/AcButton.vue'
import PageBackground from '@/components/general/PageBackground.vue'
import MainModal from '@/components/general/MainModal.vue'
import ModalHeader from '@/components/general/ModalHeader.vue'
import DataTable from '@/components/tables/DataTable.vue'
import PeriodReport from '@/components/reports/PeriodReport.vue'
import NameField from '@/components/fields/NameField.vue'
import NameSimple from '@/components/fields/NameSimple.vue'
import SubmitButtons from '@/components/fields/SubmitButtons.vue'
import SelectAdd from '@/components/fields/SelectAdd.vue'
import ExportBtn from '@/components/general/ExportBtn.vue'
import ActionBar from '@/components/general/ActionBar.vue'
import ProductScanner from '@/components/general/ProductScanner.vue'
import StatCard from '@/components/general/StatCard.vue'
import ShamsiDatePicker from '@/components/general/ShamsiDatePicker.vue'
import ShortcutsPanel from '@/components/general/ShortcutsPanel.vue'
import TabTitle from '@/components/TabTitle.vue'
import AttachmentBox from '@/components/AttachmentBox.vue'
import AvatarUpload from '@/components/AvatarUpload.vue'
import ProjectMap from '@/components/ProjectMap.vue'

export default defineBoot(({ app }) => {
  const auth = useAuthStore()

  // Toasts appear bottom-centre with a soft rounded look (not the old corner).
  Notify.setDefaults({
    position: 'bottom',
    timeout: 2600,
    progress: true,
    classes: 'app-toast',
    actions: [{ icon: 'close', color: 'white', round: true, dense: true }]
  })

  // Date formatting helpers available in all templates as $fmtDate / $fmtDateTime
  app.config.globalProperties.$fmtDate = fmtDate
  app.config.globalProperties.$fmtDateTime = fmtDateTime

  // Legacy global helpers
  app.config.globalProperties.$axios = api
  app.config.globalProperties.$api = api

  // Permission check (Super Admin bypasses, matching the store getter)
  app.config.globalProperties.$can = (permission) => {
    if (!permission) return true
    return auth.can(permission)
  }

  // Confirm + delete helper used as `this.$delete('resource/id')`
  app.config.globalProperties.$delete = (url, onDone) => {
    Dialog.create({
      title: 'Delete',
      message: 'Are you sure you want to delete this record?',
      cancel: true,
      persistent: true,
      ok: { label: 'Delete', color: 'negative', unelevated: true }
    }).onOk(async () => {
      try {
        await api.delete(`/${String(url).replace(/^\//, '')}`)
        Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Deleted successfully' })
        if (typeof onDone === 'function') onDone()
      } catch (e) {
        Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Delete failed' })
      }
    })
  }

  // Global component aliases (match legacy local names)
  app.component('m-header', AppHeader)
  // The signature action button. `progress-btn` stays registered as an
  // alias so every page ported from the legacy app picks up the new
  // shape without an edit — the knob is gone, the icons stayed.
  app.component('ac-btn', AcButton)
  app.component('progress-btn', AcButton)
  app.component('m-backgrounds', PageBackground)
  app.component('m-modal', MainModal)
  app.component('n-header', ModalHeader)
  app.component('n-table', DataTable)
  app.component('period-report', PeriodReport)
  app.component('n-name', NameField)
  app.component('n-simple', NameSimple)
  app.component('n-submit', SubmitButtons)
  app.component('n-select-add', SelectAdd)
  app.component('export-btn', ExportBtn)
  app.component('action-bar', ActionBar)
  app.component('stat-card', StatCard)
  // Scanning is a whole-app capability, not a page feature.
  app.component('product-scanner', ProductScanner)
  app.component('shamsi-date', ShamsiDatePicker)
  app.component('shortcuts-panel', ShortcutsPanel)
  app.component('tab-title', TabTitle)
  app.component('attach-box', AttachmentBox)
  app.component('avatar-box', AvatarUpload)
  app.component('project-map', ProjectMap)
})
