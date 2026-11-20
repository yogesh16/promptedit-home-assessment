<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Coins, LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

interface CreditTransaction {
    id: number;
    type: 'purchase' | 'charge';
    amount: string;
    balance_after: string;
    description: string | null;
    created_at: string | null;
}

interface PaginatedTransactions {
    data: CreditTransaction[];
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    balance: string;
    transactions: PaginatedTransactions;
}>();

const page = usePage<SharedData & Record<string, unknown>>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];

const presets = [10, 25, 50, 100];

const form = useForm({ amount: '25' });

function purchase(amount?: number): void {
    if (amount !== undefined) {
        form.amount = String(amount);
    }

    form.post(route('credits.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('amount'),
    });
}

function formatMoney(value: string): string {
    const amount = Number(value);

    if (!Number.isFinite(amount)) {
        return `$${value}`;
    }

    return amount.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    });
}

function formatDate(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString();
}
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div
                v-if="flashSuccess"
                class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200"
            >
                {{ flashSuccess }}
            </div>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                        <Coins class="h-4 w-4" />
                        Available balance
                    </div>
                    <p class="mt-2 text-3xl font-semibold">{{ formatMoney(props.balance) }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Credits are deducted only after a generation succeeds. Failed generations are free.
                    </p>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <h2 class="text-sm font-medium">Simulated credit purchase</h2>
                    <p class="mt-1 text-xs text-muted-foreground">No real payment is processed.</p>

                    <form class="mt-4 space-y-4" @submit.prevent="purchase()">
                        <div class="grid gap-2">
                            <Label for="amount">Amount (USD)</Label>
                            <Input id="amount" v-model="form.amount" type="number" min="1" max="1000" step="0.01" required />
                            <InputError :message="form.errors.amount" />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="preset in presets"
                                :key="preset"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="form.processing"
                                @click="purchase(preset)"
                            >
                                Add ${{ preset }}
                            </Button>
                        </div>

                        <Button type="submit" :disabled="form.processing">
                            <LoaderCircle v-if="form.processing" class="animate-spin" />
                            Add custom amount
                        </Button>
                    </form>
                </div>
            </section>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Transaction history</h2>
                </div>

                <div v-if="props.transactions.data.length === 0" class="p-10 text-center text-sm text-muted-foreground">
                    No credit transactions yet.
                </div>

                <template v-else>
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-sidebar-border/70 text-left text-xs uppercase text-muted-foreground dark:border-sidebar-border"
                                >
                                    <th class="p-4">Type</th>
                                    <th class="p-4">Description</th>
                                    <th class="p-4">Amount</th>
                                    <th class="p-4">Balance after</th>
                                    <th class="p-4">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="transaction in props.transactions.data"
                                    :key="transaction.id"
                                    class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                                >
                                    <td class="p-4">
                                        <span
                                            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                            :class="
                                                transaction.type === 'purchase'
                                                    ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                                                    : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'
                                            "
                                        >
                                            {{ transaction.type }}
                                        </span>
                                    </td>
                                    <td class="p-4">{{ transaction.description ?? '—' }}</td>
                                    <td
                                        class="p-4"
                                        :class="
                                            transaction.type === 'purchase' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                                        "
                                    >
                                        {{ transaction.type === 'purchase' ? '+' : '−' }}{{ formatMoney(transaction.amount) }}
                                    </td>
                                    <td class="p-4">{{ formatMoney(transaction.balance_after) }}</td>
                                    <td class="whitespace-nowrap p-4 text-muted-foreground">{{ formatDate(transaction.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-3 p-4 md:hidden">
                        <article
                            v-for="transaction in props.transactions.data"
                            :key="transaction.id"
                            class="rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium uppercase text-muted-foreground">{{ transaction.type }}</span>
                                <span
                                    :class="transaction.type === 'purchase' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                >
                                    {{ transaction.type === 'purchase' ? '+' : '−' }}{{ formatMoney(transaction.amount) }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm">{{ transaction.description ?? '—' }}</p>
                            <div class="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                                <span>Balance: {{ formatMoney(transaction.balance_after) }}</span>
                                <span>{{ formatDate(transaction.created_at) }}</span>
                            </div>
                        </article>
                    </div>
                </template>

                <div
                    v-if="props.transactions.last_page > 1"
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border"
                >
                    <span class="text-muted-foreground">Page {{ props.transactions.current_page }} of {{ props.transactions.last_page }}</span>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            v-for="link in props.transactions.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            preserve-scroll
                            class="rounded-md px-2 py-1 hover:bg-accent"
                            :class="{ 'pointer-events-none opacity-50': link.url === null, 'bg-accent font-medium': link.active }"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
