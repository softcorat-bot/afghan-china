<template>
  <div>
    <q-input v-bind="$attrs" outlined dense :color="color" :label-color="color"
      :model-value="displayValue" @update:model-value="onInput" :label="label" :readonly="shamsiMode">
      <template v-slot:prepend>
        <q-icon name="event" :color="color" />
      </template>
      <template v-slot:append>
        <q-btn flat dense round size="xs" icon="swap_horiz" :color="color" @click="toggleMode">
          <q-tooltip>{{ shamsiMode ? 'Gregorian' : 'Shamsi' }}</q-tooltip>
        </q-btn>
        <q-popup-proxy v-if="shamsiMode" cover transition-show="scale" transition-hide="scale">
          <div class="shamsi-picker bg-white shadow-5 q-pa-md" style="min-width:280px">
            <div class="row items-center justify-between q-mb-sm">
              <q-btn flat dense round icon="chevron_left" @click="prevMonth" />
              <div class="text-subtitle2 text-center">{{ persianMonthName }} {{ shamsiYear }}</div>
              <q-btn flat dense round icon="chevron_right" @click="nextMonth" />
            </div>
            <div class="row text-caption text-grey-6 q-mb-xs">
              <div v-for="d in dayNames" :key="d" class="col text-center">{{ d }}</div>
            </div>
            <div class="row q-col-gutter-xs">
              <div v-for="(day, i) in calendarDays" :key="i" class="col" style="min-width:14.28%">
                <q-btn v-if="day" flat dense size="sm" :class="dayClass(day)" @click="selectDay(day)">
                  {{ day }}
                </q-btn>
              </div>
            </div>
            <q-separator class="q-my-sm" />
            <div class="text-caption text-grey-6 text-center">Gregorian: {{ gregorianEquiv }}</div>
          </div>
        </q-popup-proxy>
      </template>
    </q-input>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: 'Date' },
  color: { type: String, default: 'primary' }
})
const emit = defineEmits(['update:modelValue'])

const shamsiMode = ref(localStorage.getItem('calendar_type') === 'fa')
const today = new Date()

function toShamsi(gDate) {
  const gy = gDate.getFullYear()
  const gm = gDate.getMonth() + 1
  const gd = gDate.getDate()
  const g_d_no = 365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400)
  let n = g_d_no + ([0,31,59,90,120,151,181,212,243,273,304,334][gm-1]) + gd
  if (gm > 2 && ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0)) n++

  const j_d_no = n - 1948320
  const j_np = Math.floor(j_d_no / 1461)
  const j_d_no2 = j_d_no % 1461
  let jy, jm, jd
  if (j_d_no2 >= 366) {
    jy = 4 * j_np + 1 + Math.floor((j_d_no2 - 1) / 365)
    const r = (j_d_no2 - 1) % 365
    const jMonthDays = [31,31,31,31,31,31,30,30,30,30,30,29]
    let cum = 0; jm = 0
    for (let i = 0; i < 12; i++) {
      if (r < cum + jMonthDays[i]) { jm = i + 1; jd = r - cum + 1; break }
      cum += jMonthDays[i]
    }
  } else {
    jy = 4 * j_np
    const jMonthDays = [31,31,31,31,31,31,30,30,30,30,30,30]
    let cum = 0; jm = 0
    for (let i = 0; i < 12; i++) {
      if (j_d_no2 < cum + jMonthDays[i]) { jm = i + 1; jd = j_d_no2 - cum + 1; break }
      cum += jMonthDays[i]
    }
  }
  return { y: jy + 1348, m: jm, d: jd }
}

function shamsiToGregorian(jy, jm, jd) {
  jy -= 1348
  const j_d_no = 365 * jy + Math.floor(jy / 4) * 366 + (jm > 6 ? 186 + (jm - 7) * 30 : (jm - 1) * 31) + jd + 1948320 - 1
  let gy = Math.floor(j_d_no / 365.2425)
  let gdn = j_d_no - (365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400))
  if (gdn < 0) { gy--; gdn = j_d_no - (365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400)) }
  const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0
  const g_days = [0,31,leap?29:28,31,30,31,30,31,31,30,31,30,31]
  let gm = 0, gd = 0
  for (let i = 1; i <= 12; i++) { if (gdn < g_days[i]) { gm = i; gd = gdn + 1; break } gdn -= g_days[i] }
  return `${gy}-${String(gm).padStart(2,'0')}-${String(gd).padStart(2,'0')}`
}

const shamsiMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند']
const dayNames = ['ش','ی','د','س','چ','پ','ج']

const todayShamsi = computed(() => toShamsi(today))
const shamsiYear = ref(todayShamsi.value.y)
const shamsiMonth = ref(todayShamsi.value.m)
const selectedDay = ref(null)

const persianMonthName = computed(() => shamsiMonths[shamsiMonth.value - 1])

const calendarDays = computed(() => {
  const monthDays = shamsiMonth.value <= 6 ? 31 : shamsiMonth.value <= 11 ? 30 : 29
  const firstGreg = shamsiToGregorian(shamsiYear.value, shamsiMonth.value, 1)
  const firstDate = new Date(firstGreg)
  const startDay = (firstDate.getDay() + 1) % 7
  const days = Array.from({ length: startDay }, () => null)
  for (let d = 1; d <= monthDays; d++) days.push(d)
  return days
})

const gregorianEquiv = computed(() => {
  if (!selectedDay.value) return ''
  return shamsiToGregorian(shamsiYear.value, shamsiMonth.value, selectedDay.value)
})

const displayValue = computed(() => {
  if (!props.modelValue) return ''
  if (!shamsiMode.value) return props.modelValue
  try {
    const d = new Date(props.modelValue)
    const s = toShamsi(d)
    return `${s.y}/${String(s.m).padStart(2,'0')}/${String(s.d).padStart(2,'0')}`
  } catch { return props.modelValue }
})

function toggleMode() { shamsiMode.value = !shamsiMode.value }
function prevMonth() { if (shamsiMonth.value === 1) { shamsiMonth.value = 12; shamsiYear.value-- } else shamsiMonth.value-- }
function nextMonth() { if (shamsiMonth.value === 12) { shamsiMonth.value = 1; shamsiYear.value++ } else shamsiMonth.value++ }

function selectDay(d) {
  selectedDay.value = d
  const greg = shamsiToGregorian(shamsiYear.value, shamsiMonth.value, d)
  emit('update:modelValue', greg)
}

function dayClass(d) {
  const classes = ['full-width']
  if (d === selectedDay.value) classes.push('bg-primary text-white')
  else if (d === todayShamsi.value.d && shamsiMonth.value === todayShamsi.value.m && shamsiYear.value === todayShamsi.value.y) {
    classes.push('text-primary text-weight-bold')
  }
  return classes.join(' ')
}

function onInput(val) {
  emit('update:modelValue', val)
}

watch(() => props.modelValue, (val) => {
  if (val && shamsiMode.value) {
    try {
      const d = new Date(val)
      const s = toShamsi(d)
      shamsiYear.value = s.y
      shamsiMonth.value = s.m
      selectedDay.value = s.d
    } catch {}
  }
}, { immediate: true })
</script>

<style scoped>
.shamsi-picker { border-radius: 12px; }
</style>
