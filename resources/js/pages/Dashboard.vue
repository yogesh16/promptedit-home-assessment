<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type GenerationStatus } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, ImageOff, Images, Plus, Receipt, TrendingUp, Wallet } from 'lucide-vue-next';

interface Stats {
    credits: string;
    total_generations: number;
    queued: number;
    processing: number;
    succeeded: number;
    failed: number;
    active: number;
    total_spent: string;
    success_rate: number | null;
}

interface RecentGeneration {
    id: number;
    prompt: string;
    status: GenerationStatus;
    size: string;
    quality: string;
    customer_charge: string | null;
    image_url: string | null;
    created_at: string | null;
}

interface AdminSummary {
    generations: number;
    provider_cost: string;
    customer_charge: string;
    profit: string;
}

defineProps<{
    stats: Stats;
    recent: RecentGeneration[];
    adminSummary: AdminSummary | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const statusClasses: Record<string, string> = {
    queued: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    succeeded: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
};

function formatMoney(value: string | null): string {
    if (value === null) {
        return '—';
    }

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
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Overview</h1>
                    <p class="text-sm text-muted-foreground">Your credits, generations and recent activity.</p>
                </div>
                <Link
                    :href="route('generations.index')"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90"
                >
                    <Plus class="h-4 w-4" />
                    New generation
                </Link>
            </div>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase text-muted-foreground">Credit balance</span>
                        <Wallet class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-semibold">{{ formatMoney(stats.credits) }}</p>
                    <Link :href="route('credits.index')" class="mt-1 inline-flex items-center gap-1 text-xs text-primary underline">
                        Add credits
                        <ArrowUpRight class="h-3 w-3" />
                    </Link>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase text-muted-foreground">Generations</span>
                        <Images class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.total_generations }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <span v-if="stats.active > 0" class="text-blue-600 dark:text-blue-400">{{ stats.active }} in progress</span>
                        <span v-else>{{ stats.succeeded }} succeeded · {{ stats.failed }} failed</span>
                    </p>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase text-muted-foreground">Total spent</span>
                        <Receipt class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-semibold">{{ formatMoney(stats.total_spent) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Successful generations only</p>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase text-muted-foreground">Success rate</span>
                        <TrendingUp class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-semibold">{{ stats.success_rate === null ? '—' : `${stats.success_rate}%` }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ stats.succeeded }} succeeded · {{ stats.failed }} failed</p>
                </div>
            </section>

            <section v-if="adminSummary" class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-medium">Today (UTC) · all users</h2>
                        <p class="text-xs text-muted-foreground">{{ adminSummary.generations }} successful generations</p>
                    </div>
                    <Link :href="route('admin.reports.index')" class="inline-flex items-center gap-1 text-xs text-primary underline">
                        Open reports
                        <ArrowUpRight class="h-3 w-3" />
                    </Link>
                </div>
                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase text-muted-foreground">Provider cost</dt>
                        <dd class="mt-1 text-lg font-semibold">{{ formatMoney(adminSummary.provider_cost) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-muted-foreground">Customer charges</dt>
                        <dd class="mt-1 text-lg font-semibold">{{ formatMoney(adminSummary.customer_charge) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-muted-foreground">Profit</dt>
                        <dd class="mt-1 text-lg font-semibold text-green-600 dark:text-green-400">{{ formatMoney(adminSummary.profit) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="flex items-center justify-between border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Recent generations</h2>
                    <Link :href="route('generations.index')" class="text-xs text-primary underline">View all</Link>
                </div>

                <div v-if="recent.length === 0" class="flex flex-col items-center gap-2 p-10 text-center">
                    <ImageOff class="h-8 w-8 text-muted-foreground" />
                    <p class="text-sm font-medium">Nothing generated yet</p>
                    <p class="text-sm text-muted-foreground">Create your first image to see activity here.</p>
                    <Link :href="route('generations.index')" class="mt-2 text-sm text-primary underline">Start a generation</Link>
                </div>

                <template v-else>
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-sidebar-border/70 text-left text-xs uppercase text-muted-foreground dark:border-sidebar-border"
                                >
                                    <th class="p-4">Image</th>
                                    <th class="p-4">Prompt</th>
                                    <th class="p-4">Status</th>
                                    <th class="p-4">Charge</th>
                                    <th class="p-4">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="generation in recent"
                                    :key="generation.id"
                                    class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                                >
                                    <td class="p-4">
                                        <a v-if="generation.image_url" :href="generation.image_url" target="_blank" rel="noopener">
                                            <img
                                                :src="generation.image_url"
                                                :alt="generation.prompt"
                                                loading="lazy"
                                                class="h-12 w-12 rounded-md border object-cover"
                                            />
                                        </a>
                                        <div
                                            v-else
                                            class="flex h-12 w-12 items-center justify-center rounded-md border border-dashed text-muted-foreground"
                                        >
                                            <ImageOff class="h-4 w-4" />
                                        </div>
                                    </td>
                                    <td class="max-w-xs p-4">
                                        <p class="truncate" :title="generation.prompt">{{ generation.prompt }}</p>
                                        <p class="text-xs text-muted-foreground">{{ generation.size }} · {{ generation.quality }}</p>
                                    </td>
                                    <td class="p-4">
                                        <span
                                            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                            :class="statusClasses[generation.status]"
                                        >
                                            {{ generation.status }}
                                        </span>
                                    </td>
                                    <td class="p-4">{{ formatMoney(generation.customer_charge) }}</td>
                                    <td class="whitespace-nowrap p-4 text-muted-foreground">{{ formatDate(generation.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-3 p-4 md:hidden">
                        <article
                            v-for="generation in recent"
                            :key="generation.id"
                            class="flex gap-3 rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <a v-if="generation.image_url" :href="generation.image_url" target="_blank" rel="noopener" class="shrink-0">
                                <img
                                    :src="generation.image_url"
                                    :alt="generation.prompt"
                                    loading="lazy"
                                    class="h-16 w-16 rounded-md border object-cover"
                                />
                            </a>
                            <div
                                v-else
                                class="flex h-16 w-16 shrink-0 items-center justify-center rounded-md border border-dashed text-muted-foreground"
                            >
                                <ImageOff class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ generation.prompt }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                        :class="statusClasses[generation.status]"
                                    >
                                        {{ generation.status }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">{{ generation.size }} · {{ generation.quality }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between text-xs text-muted-foreground">
                                    <span>{{ formatMoney(generation.customer_charge) }}</span>
                                    <span>{{ formatDate(generation.created_at) }}</span>
                                </div>
                            </div>
                        </article>
                    </div>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
