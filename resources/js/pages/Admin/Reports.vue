<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface Totals {
    generations: number;
    provider_cost: string;
    customer_charge: string;
    profit: string;
}

interface DayRow extends Totals {
    date: string;
}

interface UserRow extends Totals {
    user_id: number;
    name: string | null;
    email: string | null;
}

interface ReconciliationDay {
    date: string;
    provider_generations: number;
    internal_generations: number;
    provider_cost: string;
    internal_cost: string;
    difference: string;
}

interface Reconciliation {
    start_date: string;
    end_date: string;
    days: ReconciliationDay[];
    provider_total: string;
    internal_total: string;
    total_difference: string;
    matches: boolean;
}

const props = defineProps<{
    filters: { start: string; end: string; reconciled: boolean };
    daily: { days: DayRow[]; totals: Totals };
    perUser: { users: UserRow[]; totals: Totals };
    reconciliation: Reconciliation | null;
    reconciliationError: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/admin/reports' }];

const start = ref(props.filters.start);
const end = ref(props.filters.end);
const reconcile = ref(props.filters.reconciled);
const loading = ref(false);

function applyFilters(): void {
    loading.value = true;

    router.get(
        route('admin.reports.index'),
        { start: start.value, end: end.value, reconcile: reconcile.value ? 1 : undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
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

const summary = [
    { key: 'provider_cost', label: 'Provider cost' },
    { key: 'customer_charge', label: 'Customer charges' },
    { key: 'profit', label: 'Profit' },
] as const;
</script>

<template>
    <Head title="Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <section class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <form class="flex flex-wrap items-end gap-4" @submit.prevent="applyFilters">
                    <div class="grid gap-2">
                        <Label for="start">Start (UTC)</Label>
                        <Input id="start" v-model="start" type="date" required />
                    </div>
                    <div class="grid gap-2">
                        <Label for="end">End (UTC)</Label>
                        <Input id="end" v-model="end" type="date" required />
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm">
                        <input v-model="reconcile" type="checkbox" class="h-4 w-4 rounded border-input" />
                        Check Pixelforge usage
                    </label>
                    <Button type="submit" :disabled="loading">
                        <LoaderCircle v-if="loading" class="animate-spin" />
                        Apply
                    </Button>
                </form>
                <p class="mt-2 text-xs text-muted-foreground">Ranges are limited to 92 days. Costs are grouped by UTC completion date.</p>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-xs uppercase text-muted-foreground">Generations</p>
                    <p class="mt-1 text-2xl font-semibold">{{ props.daily.totals.generations }}</p>
                </div>
                <div v-for="item in summary" :key="item.key" class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-xs uppercase text-muted-foreground">{{ item.label }}</p>
                    <p class="mt-1 text-2xl font-semibold" :class="item.key === 'profit' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ formatMoney(props.daily.totals[item.key]) }}
                    </p>
                </div>
            </section>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Daily report</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-sidebar-border/70 text-left text-xs uppercase text-muted-foreground dark:border-sidebar-border"
                            >
                                <th class="p-4">UTC date</th>
                                <th class="p-4">Generations</th>
                                <th class="p-4">Provider cost</th>
                                <th class="p-4">Customer charges</th>
                                <th class="p-4">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in props.daily.days"
                                :key="day.date"
                                class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="whitespace-nowrap p-4">{{ day.date }}</td>
                                <td class="p-4">{{ day.generations }}</td>
                                <td class="p-4">{{ formatMoney(day.provider_cost) }}</td>
                                <td class="p-4">{{ formatMoney(day.customer_charge) }}</td>
                                <td class="p-4">{{ formatMoney(day.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Per-user report</h2>
                </div>

                <div v-if="props.perUser.users.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                    No successful generations in this range.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-sidebar-border/70 text-left text-xs uppercase text-muted-foreground dark:border-sidebar-border"
                            >
                                <th class="p-4">User</th>
                                <th class="p-4">Generations</th>
                                <th class="p-4">Provider cost</th>
                                <th class="p-4">Customer charges</th>
                                <th class="p-4">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in props.perUser.users"
                                :key="user.user_id"
                                class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="p-4">
                                    <p class="font-medium">{{ user.name ?? '—' }}</p>
                                    <p class="text-xs text-muted-foreground">{{ user.email ?? '—' }}</p>
                                </td>
                                <td class="p-4">{{ user.generations }}</td>
                                <td class="p-4">{{ formatMoney(user.provider_cost) }}</td>
                                <td class="p-4">{{ formatMoney(user.customer_charge) }}</td>
                                <td class="p-4">{{ formatMoney(user.profit) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-sidebar-border/70 font-medium dark:border-sidebar-border">
                                <td class="p-4">Total</td>
                                <td class="p-4">{{ props.perUser.totals.generations }}</td>
                                <td class="p-4">{{ formatMoney(props.perUser.totals.provider_cost) }}</td>
                                <td class="p-4">{{ formatMoney(props.perUser.totals.customer_charge) }}</td>
                                <td class="p-4">{{ formatMoney(props.perUser.totals.profit) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section
                v-if="props.reconciliationError"
                class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
                <div class="flex items-center gap-2">
                    <AlertTriangle class="h-4 w-4" />
                    {{ props.reconciliationError }}
                </div>
            </section>

            <section v-if="props.reconciliation" class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Pixelforge usage reconciliation</h2>
                    <span v-if="props.reconciliation.matches" class="inline-flex items-center gap-1 text-sm text-green-600 dark:text-green-400">
                        <CheckCircle2 class="h-4 w-4" />
                        Match
                    </span>
                    <span v-else class="inline-flex items-center gap-1 text-sm text-red-600 dark:text-red-400">
                        <AlertTriangle class="h-4 w-4" />
                        Discrepancy
                    </span>
                </div>

                <div class="grid gap-4 p-4 sm:grid-cols-3">
                    <div>
                        <p class="text-xs uppercase text-muted-foreground">Provider total</p>
                        <p class="mt-1 font-medium">{{ formatMoney(props.reconciliation.provider_total) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-muted-foreground">Internal total</p>
                        <p class="mt-1 font-medium">{{ formatMoney(props.reconciliation.internal_total) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-muted-foreground">Difference</p>
                        <p class="mt-1 font-medium">{{ formatMoney(props.reconciliation.total_difference) }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto border-t border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-sidebar-border/70 text-left text-xs uppercase text-muted-foreground dark:border-sidebar-border"
                            >
                                <th class="p-4">UTC date</th>
                                <th class="p-4">Provider gens</th>
                                <th class="p-4">Internal gens</th>
                                <th class="p-4">Provider cost</th>
                                <th class="p-4">Internal cost</th>
                                <th class="p-4">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in props.reconciliation.days"
                                :key="day.date"
                                class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="p-4">{{ day.date }}</td>
                                <td class="p-4">{{ day.provider_generations }}</td>
                                <td class="p-4">{{ day.internal_generations }}</td>
                                <td class="p-4">{{ formatMoney(day.provider_cost) }}</td>
                                <td class="p-4">{{ formatMoney(day.internal_cost) }}</td>
                                <td class="p-4" :class="Number(day.difference) === 0 ? '' : 'text-red-600 dark:text-red-400'">
                                    {{ formatMoney(day.difference) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
