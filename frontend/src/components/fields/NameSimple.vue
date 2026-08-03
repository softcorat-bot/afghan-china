<template>
  <q-input
    outlined
    :label="label"
    :ref="refname"
    dense
    hide-bottom-space
    :rules="rules"
    :color="color ? color : 'primary'"
    :class="inputClass ? inputClass : 'q-mt-sm text-capitalize'"
    :type="type ? type : 'text'"
    :model-value="name"
    step="any"
    :bg-color="bgColor"
    @focus="$event.target.select()"
    @keyup="$emit('keyup')"
    @keyup.enter.prevent="$emit('onenter')"
    @blur="$emit('blur')"
    @change="$emit('change')"
    @update:model-value="onInput"
    :autofocus="autofocus"
    :error="error"
    :error-message="errorMessage"
  >
    <template v-slot:prepend>
      <q-icon :size="size ? size : 'sm'" :name="icon ? icon : 'info'" color="primary" />
    </template>
  </q-input>
</template>

<script setup>
const emit = defineEmits(['update:name', 'input', 'keyup', 'onenter', 'blur', 'change'])
defineProps(['name', 'label', 'refname', 'icon', 'type', 'size', 'bgColor', 'inputClass', 'color', 'autofocus', 'rules', 'error', 'errorMessage'])

function onInput (e) {
  emit('update:name', e)
  emit('input', e)
}
</script>
