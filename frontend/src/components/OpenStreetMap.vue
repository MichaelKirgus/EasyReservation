<template>
  <div class="osm-map">
    <div ref="mapContainer" class="map-container"></div>
    <div v-if="searchAddress" class="address-search">
      <input
        type="text"
        v-model="searchAddress"
        placeholder="Adresse suchen..."
        @keyup.enter="searchLocation"
      />
      <button @click="searchLocation">Suchen</button>
    </div>
    <div v-if="selectedMarker" class="marker-info">
      <p>Position: {{ selectedMarker.lat.toFixed(6) }}, {{ selectedMarker.lng.toFixed(6) }}</p>
      <button @click="clearMarker">Marker entfernen</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, nextTick } from 'vue'
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
  }
})

const emit = defineEmits(['update:lat', 'update:lng'])

const mapContainer = ref(null)
const map = ref(null)
const selectedMarker = ref(null)
const searchAddress = ref('')

const centerGermany = {
  lat: 51.1657,
  lng: 10.4515
}

// Initial map setup with location markers
function fitMapToLocations() {
  if (!map.value || !props.locations.length) return
  
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

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
  }).addTo(map.value)

  // Click to set marker
  map.value.on('click', (e) => {
    setMarker(e.latlng.lat, e.latlng.lng)
  })

  // Initial marker if coordinates provided
  if (props.initialLat !== null && props.initialLng !== null) {
    updateMarker(props.initialLat, props.initialLng)
  }
}

function setMarker(lat, lng) {
  if (selectedMarker.value) {
    map.value.removeLayer(selectedMarker.value)
  }

  selectedMarker.value = L.marker([lat, lng]).addTo(map.value).bindPopup('Position')
  
  // Update parent component
  emit('update:lat', lat)
  emit('update:lng', lng)

  map.value.flyTo([lat, lng], 15)
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
  emit('update:lat', null)
  emit('update:lng', null)
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
  if (!map.value || !props.locations.length) return
  
  props.locations.forEach(loc => {
    if (loc.latitude !== null && loc.longitude !== null) {
      L.marker([loc.latitude, loc.longitude])
        .addTo(map.value)
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

watch(() => props.locations, (newVal) => {
  if (map.value && newVal.length > 0) {
    nextTick(() => {
      fitMapToLocations()
    })
  }
})

onUnmounted(() => {
  if (map.value) {
    map.value.remove()
    map.value = null
  }
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
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

.address-search button {
  padding: 0.5rem 1rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}

.marker-info {
  padding: 0.75rem;
  background: #f3f4f6;
  border-radius: 6px;
  font-size: 0.875rem;
}
</style>
