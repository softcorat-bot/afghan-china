<template>
  <q-card-actions class="full-width q-px-md q-py-sm" align="right">
    <q-btn
      :label="$t('Reset')"
      type="reset"
      unelevated
      text-color="grey-8"
      class="transition duration-500 ease-in-out transform hover:scale-105 bg-grey-3 my_radio_less q-px-md"
      icon="refresh"
    />
    <q-btn
      :type="type ? type : 'submit'"
      :loading="submitting"
      :disable="disable ? true : false"
      :label="label"
      unelevated
      color="primary"
      class="transition duration-500 ease-in-out transform hover:scale-105 my_radio_less q-ml-sm q-px-md"
      icon="save"
    >
      <template v-slot:loading>
        <q-spinner-facebook />
      </template>
    </q-btn>
  </q-card-actions>
</template>

<script setup>
defineProps(['submitting', 'type', 'label', 'disable'])
</script>
