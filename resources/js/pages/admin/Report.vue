<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Money } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Totals {
    generations: number;
    charged: Money;
    provider_cost: Money;
    profit: Money;
}

interface UserRow extends Totals {
    name: string;
    email: string;
}

interface Day {
    date: string;
    users: UserRow[];
    totals: Totals;
    usage_cost: string | null;
    matches_usage: boolean | null;
}

const props = defineProps<{
    start: string;
    end: string;
    days: Day[];
    grand_total: Totals;
    usage_error: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];

const range = reactive({ start: props.start, end: props.end });
const errors = usePage().props.errors as Record<string, string>;

function load() {
    router.get(route('admin.report'), { start: range.start, end: range.end }, { preserveState: true });
}
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Admin report</h1>
                <p class="text-sm text-muted-foreground">
                    Per UTC day (the day a generation completed) and per user. Only succeeded generations cost anything. Amounts in USD with 9 decimals;
                    hover for the exact value. "Pixelforge /v1/usage" is fetched live and must equal our provider cost for the day.
                </p>
            </div>

            <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
                <div class="grid gap-1">
                    <Label for="start">From</Label>
                    <Input id="start" v-model="range.start" type="date" />
                </div>
                <div class="grid gap-1">
                    <Label for="end">To</Label>
                    <Input id="end" v-model="range.end" type="date" />
                </div>
                <Button type="submit">Show</Button>
                <InputError :message="errors.start" />
            </form>

            <p v-if="usage_error" class="rounded-md border border-destructive p-3 text-sm text-destructive">{{ usage_error }}</p>

            <div class="overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="py-2 pr-3 font-normal">Day</th>
                            <th class="py-2 pr-3 font-normal">User</th>
                            <th class="py-2 pr-3 text-right font-normal">Generations</th>
                            <th class="py-2 pr-3 text-right font-normal">Charged</th>
                            <th class="py-2 pr-3 text-right font-normal">Provider cost</th>
                            <th class="py-2 pr-3 text-right font-normal">Profit</th>
                            <th class="py-2 text-right font-normal">Pixelforge /v1/usage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in days" :key="day.date">
                            <tr v-for="user in day.users" :key="day.date + user.email" class="border-b text-muted-foreground">
                                <td class="py-1.5 pr-3">{{ day.date }}</td>
                                <td class="py-1.5 pr-3">{{ user.name }} <span class="text-xs">({{ user.email }})</span></td>
                                <td class="py-1.5 pr-3 text-right">{{ user.generations }}</td>
                                <td class="py-1.5 pr-3 text-right" :title="'$' + user.charged.exact">{{ user.charged.display }}</td>
                                <td class="py-1.5 pr-3 text-right" :title="'$' + user.provider_cost.exact">{{ user.provider_cost.display }}</td>
                                <td class="py-1.5 pr-3 text-right" :title="'$' + user.profit.exact">{{ user.profit.display }}</td>
                                <td />
                            </tr>
                            <tr class="border-b font-medium">
                                <td class="py-2 pr-3">{{ day.date }}</td>
                                <td class="py-2 pr-3">Day total</td>
                                <td class="py-2 pr-3 text-right">{{ day.totals.generations }}</td>
                                <td class="py-2 pr-3 text-right" :title="'$' + day.totals.charged.exact">{{ day.totals.charged.display }}</td>
                                <td class="py-2 pr-3 text-right" :title="'$' + day.totals.provider_cost.exact">{{ day.totals.provider_cost.display }}</td>
                                <td class="py-2 pr-3 text-right" :title="'$' + day.totals.profit.exact">{{ day.totals.profit.display }}</td>
                                <td class="py-2 text-right">
                                    <template v-if="day.usage_cost !== null">
                                        {{ day.usage_cost }}
                                        <span v-if="day.matches_usage" class="text-green-700 dark:text-green-400" title="Matches our provider cost">&#10003;</span>
                                        <span v-else class="text-destructive" title="Does NOT match our provider cost">&#10007;</span>
                                    </template>
                                    <span v-else class="text-muted-foreground">n/a</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-3" colspan="2">Total {{ start }} to {{ end }}</td>
                            <td class="py-2 pr-3 text-right">{{ grand_total.generations }}</td>
                            <td class="py-2 pr-3 text-right" :title="'$' + grand_total.charged.exact">{{ grand_total.charged.display }}</td>
                            <td class="py-2 pr-3 text-right" :title="'$' + grand_total.provider_cost.exact">{{ grand_total.provider_cost.display }}</td>
                            <td class="py-2 pr-3 text-right" :title="'$' + grand_total.profit.exact">{{ grand_total.profit.display }}</td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
