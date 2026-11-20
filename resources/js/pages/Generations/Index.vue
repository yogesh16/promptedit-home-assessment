<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Generation, type PaginatedData, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ImageOff, LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    generations: PaginatedData<Generation>;
}>();

const page = usePage<SharedData & Record<string, unknown>>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generations', href: '/generations' }];

const sizes = [
    { value: '1024x1024', label: 'Square · 1024 × 1024' },
    { value: '1536x1024', label: 'Landscape · 1536 × 1024' },
    { value: '1024x1536', label: 'Portrait · 1024 × 1536' },
];

const qualities = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [''],
});

const estimate = ref<{ min: string; max: string } | null>(null);
const estimating = ref(false);
const estimateError = ref<string | null>(null);

let estimateTimer: number | undefined;
let estimateRequest = 0;

const filledReferences = computed(() => form.reference_images.map((value) => value.trim()).filter((value) => value !== ''));

const referenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, message]) => message),
);

const pricingError = computed(() => (form.errors as Record<string, string | undefined>).pricing);

const statusClasses: Record<string, string> = {
    queued: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    succeeded: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function fetchEstimate(): Promise<void> {
    window.clearTimeout(estimateTimer);

    if (form.prompt.trim() === '') {
        estimate.value = null;
        estimateError.value = null;
        estimating.value = false;

        return;
    }

    const requestId = ++estimateRequest;
    estimating.value = true;

    try {
        const response = await fetch(route('generations.estimate'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: filledReferences.value,
            }),
        });

        if (requestId !== estimateRequest) {
            return;
        }

        if (response.status === 422) {
            const data = await response.json();
            estimate.value = null;
            estimateError.value = data?.errors?.pricing?.[0] ?? 'Could not estimate the cost.';
            estimating.value = false;

            return;
        }

        if (!response.ok) {
            throw new Error('Estimate request failed.');
        }

        const data = await response.json();
        estimate.value = { min: data.estimated_cost_min, max: data.estimated_cost_max };
        estimateError.value = null;
    } catch {
        if (requestId === estimateRequest) {
            estimate.value = null;
            estimateError.value = 'Cost estimation is temporarily unavailable. Please try again.';
        }
    } finally {
        if (requestId === estimateRequest) {
            estimating.value = false;
        }
    }
}

watch([() => form.prompt, () => form.size, () => form.quality, () => form.reference_images.join('|')], () => {
    window.clearTimeout(estimateTimer);
    estimating.value = form.prompt.trim() !== '';
    estimateTimer = window.setTimeout(fetchEstimate, 400);
});

function addReference(): void {
    if (form.reference_images.length < 4) {
        form.reference_images.push('');
    }
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);

    if (form.reference_images.length === 0) {
        form.reference_images.push('');
    }
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images.map((value) => value.trim()).filter((value) => value !== ''),
    })).post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            estimate.value = null;
            estimateError.value = null;
        },
    });
}

const hasActiveGenerations = computed(() =>
    props.generations.data.some((generation) => generation.status === 'queued' || generation.status === 'processing'),
);

let pollTimer: number | undefined;

function stopPolling(): void {
    if (pollTimer !== undefined) {
        window.clearInterval(pollTimer);
        pollTimer = undefined;
    }
}

function startPolling(): void {
    stopPolling();
    pollTimer = window.setInterval(() => {
        router.reload({ only: ['generations'] });
    }, 5000);
}

watch(
    hasActiveGenerations,
    (active) => {
        if (active) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopPolling();
    window.clearTimeout(estimateTimer);
});

function formatCost(value: string | null): string {
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

function formatEstimate(generation: Generation): string {
    if (generation.estimated_cost_min === null || generation.estimated_cost_max === null) {
        return '—';
    }

    if (generation.estimated_cost_min === generation.estimated_cost_max) {
        return formatCost(generation.estimated_cost_min);
    }

    return `${formatCost(generation.estimated_cost_min)} – ${formatCost(generation.estimated_cost_max)}`;
}

function formatDate(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString();
}

function statusLabel(status: Generation['status']): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}
</script>

<template>
    <Head title="Generations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div
                v-if="flashSuccess"
                class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200"
            >
                {{ flashSuccess }}
            </div>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h1 class="text-lg font-semibold">Create an image</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Describe what you want to generate. The estimated cost is shown before you submit.
                    </p>
                </div>

                <form class="grid gap-5 p-4 md:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2 md:col-span-2">
                        <Label for="prompt">Prompt</Label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            required
                            maxlength="4000"
                            rows="4"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="flex min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <div class="flex items-center justify-between text-xs text-muted-foreground">
                            <span>Maximum 4,000 characters.</span>
                            <span>{{ form.prompt.length }} / 4000</span>
                        </div>
                        <InputError :message="form.errors.prompt" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <select
                            id="size"
                            v-model="form.size"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option v-for="option in sizes" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.size" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <select
                            id="quality"
                            v-model="form.quality"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option v-for="option in qualities" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.quality" />
                    </div>

                    <div class="grid gap-2 md:col-span-2">
                        <div class="flex items-center justify-between">
                            <Label>Reference images <span class="font-normal text-muted-foreground">(optional, max 4)</span></Label>
                            <Button type="button" variant="outline" size="sm" :disabled="form.reference_images.length >= 4" @click="addReference">
                                <Plus />
                                Add
                            </Button>
                        </div>

                        <div v-for="(reference, index) in form.reference_images" :key="index" class="flex items-center gap-2">
                            <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/reference.png" class="flex-1" />
                            <Button
                                v-if="form.reference_images.length > 1"
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove reference image"
                                @click="removeReference(index)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError v-for="(message, index) in referenceErrors" :key="index" :message="message" />
                        <InputError :message="form.errors.reference_images" />
                    </div>

                    <div class="rounded-lg border border-sidebar-border/70 bg-muted/40 p-4 dark:border-sidebar-border md:col-span-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium">Estimated cost</p>
                                <p class="text-xs text-muted-foreground">
                                    Includes the 25% service markup. You are only charged for successful generations.
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <span v-if="estimating" class="inline-flex items-center gap-2 text-muted-foreground">
                                    <LoaderCircle class="h-4 w-4 animate-spin" />
                                    Estimating…
                                </span>
                                <span v-else-if="estimateError" class="text-red-600 dark:text-red-400">{{ estimateError }}</span>
                                <span v-else-if="estimate" class="font-semibold">
                                    {{ formatCost(estimate.min) }}
                                    <template v-if="estimate.min !== estimate.max"> – {{ formatCost(estimate.max) }}</template>
                                </span>
                                <span v-else class="text-muted-foreground">Enter a prompt to see the estimate</span>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="pricingError" />
                    </div>

                    <div class="flex items-center gap-3 md:col-span-2">
                        <Button type="submit" :disabled="form.processing || estimating">
                            <LoaderCircle v-if="form.processing" class="animate-spin" />
                            Generate
                        </Button>
                        <span v-if="form.processing" class="text-sm text-muted-foreground">Queueing your generation…</span>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="flex items-center justify-between border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-semibold">Generation history</h2>
                    <span v-if="hasActiveGenerations" class="inline-flex items-center gap-2 text-xs text-muted-foreground">
                        <LoaderCircle class="h-3 w-3 animate-spin" />
                        Updating…
                    </span>
                </div>

                <div v-if="props.generations.data.length === 0" class="flex flex-col items-center gap-2 p-10 text-center">
                    <ImageOff class="h-8 w-8 text-muted-foreground" />
                    <p class="text-sm font-medium">No generations yet</p>
                    <p class="text-sm text-muted-foreground">Create your first image with the form above.</p>
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
                                    <th class="p-4">Size</th>
                                    <th class="p-4">Quality</th>
                                    <th class="p-4">Status</th>
                                    <th class="p-4">Estimated</th>
                                    <th class="p-4">Charge</th>
                                    <th class="p-4">Created</th>
                                    <th class="p-4">Completed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="generation in props.generations.data"
                                    :key="generation.id"
                                    class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border"
                                >
                                    <td class="p-4">
                                        <a v-if="generation.image_url" :href="generation.image_url" target="_blank" rel="noopener">
                                            <img
                                                :src="generation.image_url"
                                                :alt="generation.prompt"
                                                loading="lazy"
                                                class="h-14 w-14 rounded-md border object-cover"
                                            />
                                        </a>
                                        <div
                                            v-else
                                            class="flex h-14 w-14 items-center justify-center rounded-md border border-dashed text-muted-foreground"
                                        >
                                            <ImageOff class="h-5 w-5" />
                                        </div>
                                    </td>
                                    <td class="max-w-xs p-4">
                                        <p class="truncate" :title="generation.prompt">{{ generation.prompt }}</p>
                                        <p
                                            v-if="generation.status === 'failed' && generation.error_message"
                                            class="mt-1 truncate text-xs text-red-600 dark:text-red-400"
                                            :title="generation.error_message"
                                        >
                                            {{ generation.error_message }}
                                        </p>
                                    </td>
                                    <td class="whitespace-nowrap p-4">{{ generation.size }}</td>
                                    <td class="p-4 capitalize">{{ generation.quality }}</td>
                                    <td class="p-4">
                                        <span
                                            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="statusClasses[generation.status]"
                                        >
                                            {{ statusLabel(generation.status) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap p-4">{{ formatEstimate(generation) }}</td>
                                    <td class="whitespace-nowrap p-4">{{ formatCost(generation.customer_charge) }}</td>
                                    <td class="whitespace-nowrap p-4 text-muted-foreground">{{ formatDate(generation.created_at) }}</td>
                                    <td class="whitespace-nowrap p-4 text-muted-foreground">{{ formatDate(generation.completed_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-4 p-4 md:hidden">
                        <article
                            v-for="generation in props.generations.data"
                            :key="generation.id"
                            class="rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <div class="flex gap-3">
                                <a v-if="generation.image_url" :href="generation.image_url" target="_blank" rel="noopener" class="shrink-0">
                                    <img
                                        :src="generation.image_url"
                                        :alt="generation.prompt"
                                        loading="lazy"
                                        class="h-20 w-20 rounded-md border object-cover"
                                    />
                                </a>
                                <div
                                    v-else
                                    class="flex h-20 w-20 shrink-0 items-center justify-center rounded-md border border-dashed text-muted-foreground"
                                >
                                    <ImageOff class="h-6 w-6" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium" :title="generation.prompt">{{ generation.prompt }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span
                                            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="statusClasses[generation.status]"
                                        >
                                            {{ statusLabel(generation.status) }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">{{ generation.size }} · {{ generation.quality }}</span>
                                    </div>
                                    <p
                                        v-if="generation.status === 'failed' && generation.error_message"
                                        class="mt-2 text-xs text-red-600 dark:text-red-400"
                                    >
                                        {{ generation.error_message }}
                                    </p>
                                </div>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <dt class="text-muted-foreground">Estimated</dt>
                                    <dd>{{ formatEstimate(generation) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Charge</dt>
                                    <dd>{{ formatCost(generation.customer_charge) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Created</dt>
                                    <dd>{{ formatDate(generation.created_at) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Completed</dt>
                                    <dd>{{ formatDate(generation.completed_at) }}</dd>
                                </div>
                            </dl>
                        </article>
                    </div>
                </template>

                <div
                    v-if="props.generations.last_page > 1"
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border"
                >
                    <span class="text-muted-foreground">Page {{ props.generations.current_page }} of {{ props.generations.last_page }}</span>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            v-for="link in props.generations.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            preserve-scroll
                            class="rounded-md px-2 py-1 hover:bg-accent"
                            :class="{
                                'pointer-events-none opacity-50': link.url === null,
                                'bg-accent font-medium': link.active,
                            }"
                        >
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
