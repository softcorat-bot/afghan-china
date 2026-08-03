<template>
  <q-dialog
    persistent
    :position="position ? position : 'standard'"
    :model-value="showCM"
    seamless
    transition-show="jump-up"
    transition-hide="jump-down"
    @update:model-value="$emit('update:showCM', $event)"
  >
    <div :style="(card_style ? card_style : 'width: 500px') + ';max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column;'" class="three_d_high">
      <slot></slot>
    </div>
  </q-dialog>
</template>

<script setup>
defineProps(['showCM', 'position', 'card_style'])
defineEmits(['update:showCM'])
</script>
