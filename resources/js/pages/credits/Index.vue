<script setup lang="ts">
import MoneyText from '@/components/MoneyText.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Money, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Transaction {
    id: number;
    type: 'purchase' | 'hold' | 'release' | 'charge';
    description: string | null;
    amount: Money;
    created_at: string;
}

defineProps<{
    packs: number[];
    transactions: Transaction[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];

const page = usePage<SharedData>();
const buying = ref<number | null>(null);

function buy(dollars: number) {
    buying.value = dollars;
    router.post(route('credits.store'), { dollars }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}

const typeLabel: Record<Transaction['type'], string> = {
    purchase: 'Credit added',
    hold: 'Reserved',
    release: 'Reserve released',
    charge: 'Charged',
};
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Credits</h1>
                <p class="text-2xl">Balance: <MoneyText :money="page.props.auth.balance" /></p>
                <p class="text-sm text-muted-foreground">
                    Balance already excludes amounts reserved for running generations. This is a demo: buying credit is free and no payment is taken.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <Button v-for="dollars in packs" :key="dollars" :disabled="buying !== null" @click="buy(dollars)">Buy ${{ dollars }}</Button>
            </div>

            <div>
                <h2 class="mb-2 font-medium">Ledger</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="py-2 pr-2 font-normal">When (UTC)</th>
                            <th class="py-2 pr-2 font-normal">What</th>
                            <th class="py-2 text-right font-normal">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in transactions" :key="t.id" class="border-b">
                            <td class="py-2 pr-2 text-muted-foreground">{{ t.created_at.slice(0, 16).replace('T', ' ') }}</td>
                            <td class="py-2 pr-2">
                                {{ typeLabel[t.type] }} <span class="text-muted-foreground">{{ t.description }}</span>
                            </td>
                            <td class="py-2 text-right" :class="t.amount.exact.startsWith('-') ? '' : 'text-green-700 dark:text-green-400'">
                                <MoneyText :money="t.amount" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
