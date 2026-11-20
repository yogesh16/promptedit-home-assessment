<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Generation, type PaginatedData } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generations',
        href: '/generations',
    },
];

defineProps<{
    generations: PaginatedData<Generation>;
}>();

const statusClasses: Record<string, string> = {
    queued: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    succeeded: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
};

function formatCost(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `$${value}`;
}
</script>

<template>
    <Head title="Generations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="flex items-center justify-between border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h1 class="text-lg font-semibold">Generation history</h1>
                </div>

                <div v-if="generations.data.length === 0" class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No generations yet.
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase text-gray-500 dark:border-sidebar-border dark:text-gray-400">
                            <th class="p-4">Prompt</th>
                            <th class="p-4">Size</th>
                            <th class="p-4">Quality</th>
                            <th class="p-4">Status</th>
                            <th class="p-4">Estimated</th>
                            <th class="p-4">Charge</th>
                            <th class="p-4">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="generation in generations.data"
                            :key="generation.id"
                            class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="max-w-xs truncate p-4" :title="generation.prompt">
                                {{ generation.prompt }}
                            </td>
                            <td class="p-4">{{ generation.size }}</td>
                            <td class="p-4 capitalize">{{ generation.quality }}</td>
                            <td class="p-4">
                                <span
                                    class="inline-block rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="statusClasses[generation.status]"
                                >
                                    {{ generation.status }}
                                </span>
                            </td>
                            <td class="p-4">
                                <template v-if="generation.estimated_cost_min && generation.estimated_cost_max">
                                    {{ formatCost(generation.estimated_cost_min) }}
                                    <template v-if="generation.estimated_cost_min !== generation.estimated_cost_max">
                                        – {{ formatCost(generation.estimated_cost_max) }}
                                    </template>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="p-4">{{ formatCost(generation.customer_charge) }}</td>
                            <td class="p-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ new Date(generation.created_at).toLocaleString() }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-if="generations.last_page > 1"
                    class="flex items-center justify-between border-t border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border"
                >
                    <span class="text-gray-500 dark:text-gray-400">
                        Page {{ generations.current_page }} of {{ generations.last_page }}
                    </span>
                    <div class="flex gap-2">
                        <Link
                            v-for="link in generations.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            class="rounded-md px-2 py-1 hover:bg-sidebar-accent"
                            :class="{ 'pointer-events-none opacity-50': link.url === null }"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
