<!--
  Placeholder brand mark — replace `src/assets/brand/logo-mark.svg` with the
  client's real logo file (a skyscraper + pitched-roof houses mark) once
  supplied; this component's usage sites won't need to change.
-->
<template>
  <img src="@/assets/brand/logo-mark.svg" :width="size" :height="size" alt="Afghan China Shopping Center" />
</template>

<script setup>
defineProps({ size: { type: [String, Number], default: 36 } })
</script>
