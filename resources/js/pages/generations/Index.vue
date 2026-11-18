<script setup lang="ts">
import MoneyText from '@/components/MoneyText.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Money } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, watch } from 'vue';

interface GenerationRow {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: 'pending_submit' | 'queued' | 'processing' | 'succeeded' | 'failed';
    is_final: boolean;
    error_code: string | null;
    error_message: string | null;
    estimated_typical: Money;
    estimated_max: Money;
    charge: Money | null;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
}

const props = defineProps<{
    generations: GenerationRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

// While anything is unfinished, reload just the `generations` prop every 3 seconds.
const hasUnfinished = computed(() => props.generations.some((g) => !g.is_final));
let timer: number | undefined;

function stopPolling() {
    window.clearInterval(timer);
    timer = undefined;
}

watch(
    hasUnfinished,
    (unfinished) => {
        if (unfinished && timer === undefined) {
            timer = window.setInterval(() => router.reload({ only: ['generations'] }), 3000);
        } else if (!unfinished) {
            stopPolling();
        }
    },
    { immediate: true },
);

onBeforeUnmount(stopPolling);

const statusLabel: Record<GenerationRow['status'], string> = {
    pending_submit: 'sending',
    queued: 'queued',
    processing: 'processing',
    succeeded: 'succeeded',
    failed: 'failed',
};

const statusClass: Record<GenerationRow['status'], string> = {
    pending_submit: 'bg-muted text-muted-foreground',
    queued: 'bg-muted text-muted-foreground',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    succeeded: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

// Signed image URLs stop working after 24 hours; a broken <img> falls back to the placeholder.
function hideBrokenImage(event: Event) {
    (event.target as HTMLImageElement).style.display = 'none';
}
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">History</h1>
                    <p v-if="hasUnfinished" class="text-sm text-muted-foreground">Updating every 3 seconds while something is running&hellip;</p>
                </div>
                <Button as-child><Link :href="route('generations.create')">New generation</Link></Button>
            </div>

            <p v-if="generations.length === 0" class="rounded-lg border p-8 text-center text-muted-foreground">Nothing yet. Generate your first image.</p>

            <div v-for="g in generations" :key="g.id" class="flex gap-4 rounded-lg border p-3">
                <div class="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted text-center text-xs text-muted-foreground">
                    <img v-if="g.image_url && !g.image_expired" :src="g.image_url" :alt="g.prompt" class="size-full object-cover" @error="hideBrokenImage" />
                    <span v-else-if="g.image_expired">image link expired</span>
                    <span v-else>no image</span>
                </div>

                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[g.status]">{{ statusLabel[g.status] }}</span>
                        <span class="text-xs text-muted-foreground">{{ g.size }} &middot; {{ g.quality }}</span>
                    </div>
                    <p class="line-clamp-2 text-sm">{{ g.prompt }}</p>
                    <p v-if="g.status === 'failed'" class="text-sm text-destructive">
                        {{ g.error_message }} <span class="text-xs text-muted-foreground">({{ g.error_code }})</span>
                        <span class="block text-xs text-muted-foreground">Not charged.</span>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Estimate: about <MoneyText :money="g.estimated_typical" /> / at most <MoneyText :money="g.estimated_max" />
                        <template v-if="g.is_final">
                            &middot; Charged: <strong class="text-foreground"><MoneyText :money="g.charge" /></strong>
                        </template>
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
