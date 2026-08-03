<template>
  <div ref="mapEl" class="proj-map" :style="{ height: height }"></div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
  // Display mode: array of { id, name, location, lat, lng, status, progress, currency, contract_value }
  projects: { type: Array, default: () => [] },
  height: { type: String, default: '100%' },
  interactive: { type: Boolean, default: true },
  // Pick mode: emits update:modelValue with { lat, lng } when the map is clicked/dragged
  pickable: { type: Boolean, default: false },
  modelValue: { type: Object, default: null }
})
const emit = defineEmits(['select', 'update:modelValue'])

const mapEl = ref(null)
let map = null
let markers = []
let pickMarker = null

const statusColor = (s) => ({
  active: '#16A34A', near_completion: '#0D9488', planning: '#64748B',
  awaiting_funding: '#D97706', on_hold: '#D97706', completed: '#175A8C',
  handover: '#7C3AED', cancelled: '#DC2626'
})[s] || '#175A8C'

function pinIcon (color) {
  return L.divIcon({
    className: 'proj-pin',
    html: `<svg width="26" height="34" viewBox="0 0 26 34" xmlns="http://www.w3.org/2000/svg">
      <path d="M13 0C5.8 0 0 5.8 0 13c0 9.2 13 21 13 21s13-11.8 13-21C26 5.8 20.2 0 13 0z" fill="${color}"/>
      <circle cx="13" cy="13" r="5" fill="#fff"/></svg>`,
    iconSize: [26, 34], iconAnchor: [13, 34], popupAnchor: [0, -30]
  })
}

function esc (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])) }

function initMap () {
  if (map || !mapEl.value) return
  map = L.map(mapEl.value, {
    zoomControl: props.interactive, dragging: props.interactive,
    scrollWheelZoom: props.interactive, doubleClickZoom: props.interactive,
    boxZoom: props.interactive, keyboard: props.interactive, tap: props.interactive,
    attributionControl: false
  }).setView([34.4, 65.0], 6)

  // Real map imagery with automatic fallback: if one provider is unreachable
  // from the user's network/region, the map switches to the next one so a real
  // basemap always shows instead of a grey box.
  const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Esri' })
  const labels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 })
  const hybrid = L.layerGroup([satellite, labels])
  const streets = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Esri' })
  const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, subdomains: 'abc', attribution: '© OpenStreetMap', crossOrigin: true })
  const carto = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 19, subdomains: 'abcd', attribution: '© OpenStreetMap © CARTO', crossOrigin: true })

  if (props.interactive) {
    L.control.layers(
      { Satellite: hybrid, Streets: streets, OpenStreetMap: osm, Light: carto },
      {}, { position: 'topright', collapsed: true }
    ).addTo(map)
  }

  // Provider fallback chain: satellite (Esri) → OpenStreetMap → Carto.
  const chain = [hybrid, osm, carto]
  let active = 0
  let errs = 0
  const useLayer = (i) => {
    chain.forEach((l, k) => { if (k !== i && map.hasLayer(l)) map.removeLayer(l) })
    errs = 0
    active = i
    if (!map.hasLayer(chain[i])) chain[i].addTo(map)
    const probe = chain[i] instanceof L.LayerGroup ? satellite : chain[i]
    probe.off('tileerror').on('tileerror', () => {
      if (++errs >= 4 && active < chain.length - 1) useLayer(active + 1)
    })
  }
  useLayer(0)

  if (props.pickable) {
    map.on('click', (e) => setPick(e.latlng.lat, e.latlng.lng))
    if (props.modelValue?.lat != null) setPick(props.modelValue.lat, props.modelValue.lng, false)
  } else {
    renderMarkers()
  }
}

function setPick (lat, lng, emitUp = true) {
  if (!map) return
  const ll = [Number(lat), Number(lng)]
  if (pickMarker) pickMarker.setLatLng(ll)
  else {
    pickMarker = L.marker(ll, { icon: pinIcon('#DC2626'), draggable: true }).addTo(map)
    pickMarker.on('dragend', () => { const p = pickMarker.getLatLng(); emit('update:modelValue', { lat: +p.lat.toFixed(6), lng: +p.lng.toFixed(6) }) })
  }
  map.setView(ll, Math.max(map.getZoom(), 12))
  if (emitUp) emit('update:modelValue', { lat: +Number(lat).toFixed(6), lng: +Number(lng).toFixed(6) })
}

function renderMarkers () {
  if (!map) return
  markers.forEach(m => map.removeLayer(m)); markers = []
  const pts = []
  for (const p of props.projects) {
    if (p.lat == null || p.lng == null) continue
    const ll = [Number(p.lat), Number(p.lng)]
    pts.push(ll)
    const gmaps = `https://www.google.com/maps?q=${ll[0]},${ll[1]}`
    const m = L.marker(ll, { icon: pinIcon(statusColor(p.status)) }).addTo(map)
    m.bindPopup(
      `<div class="pm-pop"><b>${esc(p.name)}</b>` +
      (p.location ? `<div class="pm-sub">${esc(p.location)}</div>` : '') +
      `<div class="pm-sub">${esc(p.progress ?? 0)}% · ${esc(p.status || '')}</div>` +
      `<div class="pm-links"><a href="#" data-id="${p.id}" class="pm-open">Open</a>` +
      `<a href="${gmaps}" target="_blank" rel="noopener">Google Maps ↗</a></div></div>`
    )
    m.on('popupopen', (e) => {
      const el = e.popup.getElement()?.querySelector('.pm-open')
      if (el) el.addEventListener('click', (ev) => { ev.preventDefault(); emit('select', p.id) })
    })
    markers.push(m)
  }
  if (pts.length === 1) map.setView(pts[0], 12)
  else if (pts.length > 1) map.fitBounds(pts, { padding: [30, 30] })
}

watch(() => props.projects, () => { if (!props.pickable) renderMarkers() }, { deep: true })
watch(() => props.modelValue, (v) => { if (props.pickable && v?.lat != null) setPick(v.lat, v.lng, false) })

onMounted(() => nextTick(initMap))
onBeforeUnmount(() => { if (map) { map.remove(); map = null } })

// Let parents force a redraw after the container becomes visible (dialogs).
defineExpose({ invalidate: () => nextTick(() => map && map.invalidateSize()) })
</script>

<style scoped>
.proj-map { width: 100%; border-radius: 12px; overflow: hidden; z-index: 0; }
:deep(.proj-pin) { background: none; border: none; }
:deep(.pm-pop) { font-size: 12px; min-width: 130px; }
:deep(.pm-sub) { color: #64748B; font-size: 11px; margin-top: 1px; }
:deep(.pm-links) { display: flex; gap: 10px; margin-top: 6px; }
:deep(.pm-links a) { color: #175A8C; font-weight: 700; text-decoration: none; font-size: 11px; }
</style>
