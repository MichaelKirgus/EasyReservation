<script setup>
import { computed } from 'vue'

const props = defineProps({
  icon: { type: String, default: 'plus' },
  iconSrc: { type: String, default: '' },
  label: { type: String, required: true },
  variant: { type: String, default: 'primary' },
  size: { type: String, default: 'md' },
  type: { type: String, default: 'button' },
  as: { type: String, default: 'button' }, // Neu: steuert das Tag
})

const ICONS = {
  lock: { viewBox: '0 0 24 24', paths: ['M17 11V7a5 5 0 0 0-10 0v4', 'M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2z', 'M12 17v-2'] },
  plus: { viewBox: '0 0 24 24', paths: ['M12 5v14', 'M5 12h14'] },
  trash: { viewBox: '0 0 24 24', paths: ['M6 7h12', 'M9 7V5h6v2', 'M10 11v6', 'M14 11v6', 'M5 7v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7'] },
  pencil: { viewBox: '0 0 24 24', paths: ['M12 20h9', 'M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19.5 3 21l1.5-4L16.5 3.5z'] },
  trash2: { viewBox: '0 0 24 24', paths: ['M3 6h18', 'M19 6v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 'M9 6V4h6v2', 'm9 10-6 6', 'm9 16-6-6'] },
  save: { viewBox: '0 0 24 24', paths: ['M5 5h11l3 3v11H5z', 'M9 5v6h6V5'] },
  refresh: { viewBox: '0 0 24 24', paths: ['M4 4v6h6', 'M20 20v-6h-6', 'M5 14a7 7 0 0 0 12 3', 'M19 10a7 7 0 0 0-12-3'] },
  download: { viewBox: '0 0 24 24', paths: ['M12 3v14', 'M6 11l6 6 6-6', 'M5 21h14'] },
  send: { viewBox: '0 0 24 24', paths: ['M22 2L11 13', 'M22 2 15 22 11 13 2 9z'] },
  check: { viewBox: '0 0 24 24', paths: ['m5 13 4 4L19 7'] },
  mail: { viewBox: '0 0 24 24', paths: ['M4 6h16v12H4z', 'm4 8 4 3 4-3'] },
  close: { viewBox: '0 0 24 24', paths: ['M18 6L6 18', 'M6 6l12 12'] },
  shield: { viewBox: '0 0 24 24', paths: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'] },
  arrowUp: { viewBox: '0 0 24 24', paths: ['M12 19V5', 'M5 12l7-7 7 7'] },
  key: { viewBox: '0 0 24 24', paths: ['M21 7a4 4 0 1 0-5 3.87V13l-2 2-2-2-2 2-2-2-2 2v3h3l2-2 2 2 5-5V10.87A4 4 0 0 0 21 7z', 'M19 7a2 2 0 1 1-4 0 2 2 0 0 1 4 0z'] },
  logout: { viewBox: '0 0 24 24', paths: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M14 7l5 5-5 5', 'M19 12H9'] },
  login: { viewBox: '0 0 24 24', paths: ['M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4', 'M10 17l5-5-5-5', 'M15 12H3'] },
  cancel: { viewBox: '0 0 24 24', paths: ['M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z', 'M15 9l-6 6', 'M9 9l6 6'] },
  image: { viewBox: '0 0 24 24', paths: ['M4 5h16v14H4z', 'm7 13 3-3 3 4 3-4 2 3', 'M8.5 9.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z'] },
  repeat: { viewBox: '0 0 24 24', paths: ['M17 1.99 21 6l-4 4', 'M3 11V9a4 4 0 0 1 4-4h14', 'M7 22.01 3 18l4-4', 'M21 13v2a4 4 0 0 1-4 4H3'] },
  clock: { viewBox: '0 0 24 24', paths: ['M12 7v5l3 3', 'M12 22a10 10 0 1 1 0-20 10 10 0 0 1 0 20z'] },
  columns: { viewBox: '0 0 24 24', paths: ['M4 5h6v14H4z', 'M14 5h6v14h-6z'] },
  chevronLeft: { viewBox: '0 0 24 24', paths: ['M15 18 9 12l6-6'] },
  chevronRight: { viewBox: '0 0 24 24', paths: ['m9 18 6-6-6-6'] },
  play: { viewBox: '0 0 24 24', paths: ['M8 5v14l11-7z'] }, // Play-Icon hinzugefügt
  eye: { viewBox: '0 0 24 24', paths: ['M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z', 'M12 15a3 3 0 1 1 0-6 3 3 0 0 1 0 6z'] },
  eyeOff: { viewBox: '0 0 24 24', paths: ['M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.81 21.81 0 0 1 5.06-6.06', 'M1 1l22 22', 'M9.53 9.53A3 3 0 0 0 12 15a3 3 0 0 0 2.47-5.47'] },
  copy: { viewBox: '0 0 24 24', paths: ['M16 1H4a2 2 0 0 0-2 2v14', 'M8 5h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5z'] },
}

const iconDef = computed(() => ICONS[props.icon] || ICONS.plus)
const classes = computed(() => ['icon-btn', `variant-${props.variant}`, `size-${props.size}`])
</script>

<template>
<component
  :is="props.as"
  v-bind="$attrs"
  :type="props.as === 'button' ? props.type : undefined"
  :aria-label="props.label"
  :title="$attrs.title || props.label"
  :class="classes"
  :disabled="props.as === 'button' ? $attrs.disabled : undefined"
  @click="$emit('click', $event)"
>
  <span class="icon" aria-hidden="true">
    <img v-if="iconSrc" :src="iconSrc" alt="" />
    <svg v-else :viewBox="iconDef.viewBox" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path v-for="(p, idx) in iconDef.paths" :key="idx" :d="p" />
    </svg>
  </span>
</component>
</template>

<style scoped>
.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--border-strong);
  background: var(--primary);
  color: var(--primary-contrast);
  cursor: pointer;
  border-radius: 10px;
  padding: 0.45rem;
  transition: background-color 0.2s ease, border-color 0.2s ease;
  font: inherit;
  min-width: 32px;
  min-height: 32px;
  box-sizing: border-box;
}
.icon-btn[disabled], .icon-btn.disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.icon-btn.variant-ghost {
  background: var(--surface-strong);
  color: var(--primary);
  border: 1px solid var(--border-strong);
}
.icon-btn.variant-primary {
  background: var(--primary);
  color: var(--primary-contrast);
  border: 1px solid var(--primary);
}
.icon-btn.size-sm {
  min-width: 28px;
  min-height: 28px;
  padding: 0.25rem;
  font-size: 1rem;
}
.icon-btn.size-md {
  min-width: 32px;
  min-height: 32px;
  padding: 0.45rem;
  font-size: 1.15rem;
}
.icon-btn.size-lg {
  min-width: 40px;
  min-height: 40px;
  padding: 0.65rem;
  font-size: 1.25rem;
}
.icon-btn:focus {
  outline: 2px solid var(--focus, var(--primary));
  outline-offset: 2px;
}
.icon-btn:active {
  filter: brightness(0.95);
}
.icon-btn.variant-ghost { background: var(--surface-strong); color: var(--primary); border-color: var(--border-strong); }
.icon-btn.variant-danger { background: #dc2626; color: #fff; border-color: #b91c1c; }
.icon-btn.variant-success {background: #22c55e; color: #fff; border-color: #16a34a; }
.icon-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.size-sm { width: 34px; height: 34px; }
.size-md { width: 40px; height: 40px; }
.size-lg { width: 44px; height: 44px; }
.icon { width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; }
.icon svg { width: 100%; height: 100%; }
.icon img { width: 100%; height: 100%; object-fit: contain; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
</style>
