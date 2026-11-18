<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import MoneyText from '@/components/MoneyText.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Money, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

defineProps<{
    sizes: string[];
    qualities: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const page = usePage<SharedData>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: ['', '', '', ''],
});

// Laravel also reports errors for keys that are not form fields (`balance`, `reference_images.0`).
const errors = computed(() => form.errors as Record<string, string | undefined>);

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

// ---- live estimate -------------------------------------------------------
// The server does all the pricing. We only send the prompt LENGTH, size, quality and the number of
// reference images, a bit after the user stops typing.

interface Estimate {
    typical: Money;
    max: Money;
    affordable: boolean;
    pricing_source: string;
}

const estimate = ref<Estimate | null>(null);
const estimateFailed = ref(false);

// Array.from counts Unicode characters (not UTF-16 units), like Pixelforge and PHP's mb_strlen.
const promptChars = computed(() => Array.from(form.prompt.trim()).length);
const imageCount = computed(() => form.reference_images.filter((url) => url.trim() !== '').length);

let timer: number | undefined;
let latestRequest = 0;

async function loadEstimate() {
    const request = ++latestRequest;
    const query = new URLSearchParams({
        chars: String(Math.min(promptChars.value, 4000)),
        size: form.size,
        quality: form.quality,
        images: String(imageCount.value),
    });

    try {
        const response = await fetch(route('generations.estimate') + '?' + query, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('estimate failed');
        const data = (await response.json()) as Estimate;
        if (request === latestRequest) {
            estimate.value = data;
            estimateFailed.value = false;
        }
    } catch {
        if (request === latestRequest) estimateFailed.value = true;
    }
}

watch(
    [promptChars, () => form.size, () => form.quality, imageCount, () => page.props.auth.balance?.exact],
    () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(loadEstimate, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearTimeout(timer));

const canSubmit = computed(() => !form.processing && form.prompt.trim() !== '' && promptChars.value <= 4000 && estimate.value?.affordable === true);

function submit() {
    form.post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Generate an image</h1>
                <p class="text-sm text-muted-foreground">
                    You pay Pixelforge's cost plus 25%. The most it could cost is reserved from your balance and released when the image is done. Failed
                    generations are free.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="prompt">Prompt</Label>
                    <textarea id="prompt" v-model="form.prompt" rows="5" :class="selectClass + ' h-auto py-2'" placeholder="A lighthouse on a cliff at dawn, oil painting" />
                    <div class="flex justify-between text-xs text-muted-foreground">
                        <InputError :message="form.errors.prompt" />
                        <span :class="promptChars > 4000 ? 'text-destructive' : ''">{{ promptChars }} / 4000</span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <select id="size" v-model="form.size" :class="selectClass">
                            <option v-for="size in sizes" :key="size" :value="size">{{ size }}</option>
                        </select>
                        <InputError :message="form.errors.size" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <select id="quality" v-model="form.quality" :class="selectClass">
                            <option v-for="quality in qualities" :key="quality" :value="quality">{{ quality }}</option>
                        </select>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Reference image URLs (optional, up to 4; each adds 765 input tokens)</Label>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="grid gap-1">
                        <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                        <InputError :message="errors[`reference_images.${index}`]" />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                </div>

                <div class="rounded-lg border p-4">
                    <div v-if="estimate" class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-lg">
                            about <strong><MoneyText :money="estimate.typical" /></strong>
                            <span class="text-muted-foreground"> &middot; at most </span>
                            <strong><MoneyText :money="estimate.max" /></strong>
                        </span>
                        <span class="text-sm text-muted-foreground">
                            Balance: <MoneyText :money="page.props.auth.balance" />
                        </span>
                    </div>
                    <p v-else-if="estimateFailed" class="text-sm text-destructive">Could not load the estimate. Try again in a moment.</p>
                    <p v-else class="text-sm text-muted-foreground">Calculating estimate&hellip;</p>

                    <p v-if="estimate?.pricing_source === 'fallback'" class="mt-2 text-xs text-amber-600">
                        Pixelforge's pricing is unreachable, so this estimate uses built-in fallback rates.
                    </p>
                    <p v-if="estimate && !estimate.affordable" class="mt-2 text-sm text-destructive">
                        Your balance is below the most this could cost.
                        <Link :href="route('credits.index')" class="underline">Buy credits</Link>
                    </p>
                    <InputError class="mt-2" :message="errors.balance" />
                </div>

                <Button type="submit" :disabled="!canSubmit">{{ form.processing ? 'Submitting…' : 'Generate' }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
