<template>
  <div class="osm-map">
    <div class="address-search">
      <input
        type="text"
        v-model="searchAddress"
        :placeholder="tr('admin_locations_map_search_placeholder', 'Adresse suchen...')"
        @keyup.enter="searchLocation"
      />
      <button @click="searchLocation">{{ tr('admin_locations_map_search_button', 'Suchen') }}</button>
      <button @click="useCurrentLocation" type="button">{{ tr('admin_locations_map_my_location_button', 'Mein Standort') }}</button>
    </div>
    <div ref="mapContainer" class="map-container"></div>
    <div v-if="selectedCoords" class="marker-info">
      <p>{{ tr('admin_locations_map_position_label', 'Position') }}: {{ selectedCoords.lat.toFixed(6) }}, {{ selectedCoords.lng.toFixed(6) }}</p>
      <button @click="clearMarker">{{ tr('admin_locations_map_clear_marker', 'Marker entfernen') }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, nextTick } from 'vue'
import { useTranslation } from '../composables/useTranslation'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// Fix for default marker icons in Vite/ESM environment
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'

delete L.Icon.Default.prototype._getIconUrl
L.Icon.Default.mergeOptions({
  iconRetinaUrl: markerIcon,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
})

const props = defineProps({
  initialLat: {
    type: Number,
    default: null
  },
  initialLng: {
    type: Number,
    default: null
  },
  locations: {
    type: Array,
    default: () => []
  },
  showAllLocations: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits(['update:lat', 'update:lng'])
const { tr } = useTranslation()

const mapContainer = ref(null)
const map = ref(null)
const selectedMarker = ref(null)
const selectedCoords = ref(null)
const searchAddress = ref('')
const locating = ref(false)
let locationsLayer = null
function refreshMarkers() {
  if (selectedMarker.value && selectedCoords.value) {
    selectedMarker.value.setLatLng(selectedCoords.value)
  }
  if (locationsLayer) {
    locationsLayer.eachLayer(layer => {
      if (layer.getLatLng) {
        layer.setLatLng(layer.getLatLng())
      }
    })
  }
}

function clearLocationMarkers() {
  if (locationsLayer) {
    locationsLayer.clearLayers()
  }
}

const centerGermany = {
  lat: 51.1657,
  lng: 10.4515
}

const isTouchDevice = typeof window !== 'undefined' && ('ontouchstart' in window || navigator.maxTouchPoints > 0)
let wheelHandler = null

// Initial map setup with location markers
function fitMapToLocations() {
  if (!map.value || !props.locations.length || !props.showAllLocations) return
  
  const latLngs = props.locations
    .filter(loc => loc.latitude !== null && loc.longitude !== null)
    .map(loc => [loc.latitude, loc.longitude])
  
  if (latLngs.length > 0) {
    const bounds = L.latLngBounds(latLngs)
    map.value.fitBounds(bounds.pad(0.1))
  }
}

watch(() => props.initialLat, (newVal) => {
  if (newVal !== null && map.value) {
    updateMarker(newVal, props.initialLng)
  }
})

watch(() => props.initialLng, (newVal) => {
  if (newVal !== null && map.value) {
    updateMarker(props.initialLat, newVal)
  }
})

function initMap() {
  if (!mapContainer.value) return
  
  // Check if map already exists for this container and destroy it
  if (map.value) {
    map.value.remove()
    map.value = null
  }
  
  map.value = L.map(mapContainer.value).setView(
    [props.initialLat || centerGermany.lat, props.initialLng || centerGermany.lng],
    5
  )

  // Require Ctrl+wheel to zoom on non-touch devices to prevent accidental scroll zoom
  if (!isTouchDevice) {
    map.value.scrollWheelZoom.disable()
    wheelHandler = (e) => {
      if (!map.value) return
      if (!e.ctrlKey) return
      e.preventDefault()
      e.stopPropagation()
      const handler = map.value.scrollWheelZoom
      if (handler && typeof handler._onWheelScroll === 'function') {
        handler.enable()
        handler._onWheelScroll(e)
        // Re-disable immediately to avoid non-ctrl scrolls changing zoom
        setTimeout(() => handler.disable(), 0)
      }
    }
    mapContainer.value.addEventListener('wheel', wheelHandler, { passive: false })
  }

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
  }).addTo(map.value)

  // Click to set marker
  map.value.on('click', (e) => {
    setMarker(e.latlng.lat, e.latlng.lng)
  })
  map.value.on('zoomend', refreshMarkers)

  // Initial marker if coordinates provided
  if (props.initialLat !== null && props.initialLng !== null) {
    updateMarker(props.initialLat, props.initialLng)
  } else if (props.showAllLocations) {
    addLocationMarkers()
    fitMapToLocations()
  } else {
    clearLocationMarkers()
  }
}

function setMarker(lat, lng) {
  if (selectedMarker.value) {
    map.value.removeLayer(selectedMarker.value)
  }

  selectedMarker.value = L.marker([lat, lng], { draggable: true })
    .addTo(map.value)
    .bindPopup('Position')

  selectedMarker.value.on('dragend', (e) => {
    const { lat: newLat, lng: newLng } = e.target.getLatLng()
    selectedCoords.value = { lat: newLat, lng: newLng }
    emit('update:lat', newLat)
    emit('update:lng', newLng)
  })
  
  // Update parent component
  emit('update:lat', lat)
  emit('update:lng', lng)
  selectedCoords.value = { lat, lng }
  const currentZoom = map.value?.getZoom?.() ?? 15
  map.value?.setView([lat, lng], currentZoom)
}

function updateMarker(lat, lng) {
  if (!map.value || lat === null || lng === null) return

  setMarker(lat, lng)
}

function clearMarker() {
  if (selectedMarker.value) {
    map.value.removeLayer(selectedMarker.value)
    selectedMarker.value = null
  }
  selectedCoords.value = null
  emit('update:lat', null)
  emit('update:lng', null)
}

function useCurrentLocation() {
  if (!navigator.geolocation || locating.value) return
  locating.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const { latitude, longitude } = pos.coords
      setMarker(latitude, longitude)
      const currentZoom = map.value?.getZoom?.() ?? 15
      map.value?.setView([latitude, longitude], currentZoom)
      locating.value = false
    },
    () => { locating.value = false },
    { enableHighAccuracy: true, timeout: 10000 }
  )
}

async function searchLocation() {
  if (!searchAddress.value.trim()) return

  try {
    const response = await fetch(
      `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchAddress.value)}`
    )
    const data = await response.json()

    if (data && data.length > 0) {
      const lat = parseFloat(data[0].lat)
      const lng = parseFloat(data[0].lon)
      setMarker(lat, lng)
      
      // Update search input with formatted address
      searchAddress.value = data[0].display_name
    }
  } catch (error) {
    console.error('Search error:', error)
  }
}

function addLocationMarkers() {
  if (!map.value || !props.locations.length || !props.showAllLocations) { clearLocationMarkers(); return }

  if (locationsLayer) {
    locationsLayer.clearLayers()
  } else {
    locationsLayer = L.layerGroup().addTo(map.value)
  }

  props.locations.forEach(loc => {
    if (loc.latitude !== null && loc.longitude !== null) {
      L.marker([loc.latitude, loc.longitude])
        .addTo(locationsLayer)
        .bindPopup(`<strong>${loc.name}</strong><br>${loc.city ? loc.city + '<br>' : ''}${loc.address || ''}`)
    }
  })
}

// Destroy map when component is unmounted
import { onUnmounted } from 'vue'

onMounted(() => {
  initMap()
  nextTick(() => {
    addLocationMarkers()
    fitMapToLocations()
  })
})

watch(() => props.showAllLocations, (val) => {
  nextTick(() => {
    if (val) {
      addLocationMarkers()
      fitMapToLocations()
    } else {
      clearLocationMarkers()
    }
  })
})

watch(() => props.locations, (newVal) => {
  if (map.value && newVal.length > 0) {
    nextTick(() => {
      addLocationMarkers()
      fitMapToLocations()
    })
  }
})

onUnmounted(() => {
  if (map.value) {
    map.value.remove()
    map.value = null
  }
  if (wheelHandler && mapContainer.value) {
    mapContainer.value.removeEventListener('wheel', wheelHandler)
    wheelHandler = null
  }
  locationsLayer = null
})
</script>

<style scoped>
.osm-map {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.map-container {
  width: 100%;
  height: 400px;
  border-radius: 8px;
  overflow: hidden;
}

.address-search {
  display: flex;
  gap: 0.5rem;
}

.address-search input {
  flex: 1;
  padding: 0.5rem;
  border: 1px solid var(--border);
  border-radius: 6px;
}

.address-search button,
.marker-info button {
  padding: 0.5rem 1rem;
  background: var(--primary);
  color: var(--primary-contrast);
  border: 1px solid var(--primary);
  border-radius: 6px;
  cursor: pointer;
}

.marker-info {
  padding: 0.75rem;
  background: var(--surface-muted);
  border-radius: 6px;
  font-size: 0.875rem;
}
</style>
