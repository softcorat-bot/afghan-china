<template>
  <!-- THE single export control: progress-bar PDF + Excel buttons, nothing else.
       (Replaces the old dropdown and the duplicate ActionBar export buttons.) -->
  <div class="row items-center no-wrap">
    <progress-btn
      color="red-7"
      icon="mdi-file-pdf-box"
      :indeterminate="busy === 'pdf'"
      @click="run('pdf')"
    >
      {{ $t('PDF') }}
    </progress-btn>
    <progress-btn
      color="green-8"
      icon="mdi-microsoft-excel"
      :indeterminate="busy === 'excel'"
      @click="run('excel')"
    >
      {{ $t('Excel') }}
    </progress-btn>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { exportPdf, exportExcel } from '@/composables/useExport'

const props = defineProps({
  data: { type: Array, default: () => [] },
  columns: { type: Array, default: () => [] },
  filename: { type: String, default: 'export' },
})

const busy = ref(null)

function run(kind) {
  if (busy.value || !props.data.length) return
  busy.value = kind
  // brief tick so the progress knob animates, then run the export
  setTimeout(async () => {
    try {
      if (kind === 'pdf') await exportPdf(props.data, props.columns, props.filename)
      else exportExcel(props.data, props.columns, props.filename)
    } finally {
      busy.value = null
    }
  }, 150)
}
</script>
