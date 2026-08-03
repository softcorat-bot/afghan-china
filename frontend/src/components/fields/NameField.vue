<template>
  <q-input
    outlined
    :label="label"
    :model-value="name"
    dense
    :ref="refName"
    :color="color ? color : 'primary'"
    :type="type ? type : 'text'"
    :class="inputClass ? inputClass : 'q-mt-sm text-capitalize'"
    @focus="$event.target.select()"
    :bg-color="bgColor"
    :readonly="readonly"
    :disable="disable"
    @keyup.enter="$emit('enter')"
    @blur="$emit('blur')"
    hide-bottom-space
    @update:model-value="$emit('update:name', $event)"
    :autofocus="autofocus ? true : false"
    :rules="rules ? rules : [val => !!val || $t('FieldIsRequired')]"
  >
    <template v-slot:prepend>
      <q-icon :name="icon ? icon : 'account_circle'" color="primary" />
    </template>
  </q-input>
</template>

<script setup>
defineProps(['name', 'label', 'refName', 'autofocus', 'icon', 'bgColor', 'inputClass', 'type', 'color', 'readonly', 'disable', 'rules'])
defineEmits(['update:name', 'enter', 'blur'])
</script>
