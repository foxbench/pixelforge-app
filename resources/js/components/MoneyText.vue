<script setup lang="ts">
import { type Money } from '@/types';

// Money is formatted on the server. The short value is shown; the exact value is in the tooltip.
defineProps<{
    money: Money | null | undefined;
    prefix?: string;
}>();
</script>

<template>
    <span v-if="money" :title="'$' + money.exact" class="tabular-nums">{{ prefix }}${{ money.display }}</span>
    <span v-else class="text-muted-foreground">&mdash;</span>
</template>
