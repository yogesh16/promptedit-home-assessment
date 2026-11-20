import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: {
        success?: string | null;
    };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    estimated_cost_min: string | null;
    estimated_cost_max: string | null;
    customer_charge: string | null;
    image_url: string | null;
    error_message: string | null;
    completed_at: string | null;
    created_at: string;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export type BreadcrumbItemType = BreadcrumbItem;
