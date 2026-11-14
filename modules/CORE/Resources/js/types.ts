/* Shared types for the CORE module UI. */

export interface PaginatorMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
}

export interface Paginator<T> {
    data: T[];
    meta: PaginatorMeta;
}

export interface SelectOption {
    value: string | number;
    label: string;
    description?: string;
    /** Short right-aligned badge — a partner code, a price, a stock figure. */
    meta?: string;
    disabled?: boolean;
}

export interface FilterDef {
    key: string;
    label: string;
    kind: 'select' | 'endpoint' | 'date';
    options?: SelectOption[];
    endpoint?: string;
    placeholder?: string;
}

export interface FlashProps {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
}

export interface AclProps {
    permissions: string[];
    roles: string[];
    is_super_admin: boolean;
}

export interface CoreSharedProps {
    flash: FlashProps;
    acl: AclProps;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    email_verified_at: string | null;
    created_at: string;
}

export interface Breadcrumb {
    title: string;
    href?: string;
}

/* ----------------------------- Table rows ------------------------------ */

export interface UserRow {
    id: number;
    name: string;
    email: string;
    verified: boolean;
    roles: { id: number; name: string }[];
    created_at: string | null;
}

export interface RoleRow {
    id: number;
    name: string;
    users_count: number;
    permissions_count: number;
    created_at: string | null;
}

export interface PermissionRow {
    id: number;
    name: string;
    group: string;
    guard_name: string;
    roles_count: number;
    users_count: number;
    created_at: string | null;
}

export interface ActivityRow {
    id: number;
    log_name: string | null;
    description: string;
    event: string | null;
    subject_type: string | null;
    subject_id: number | null;
    causer: { id: number; name: string; email: string } | null;
    subject: { type: string; id: number; label: string } | null;
    created_at: string | null;
}

// ── F13: API tokens & the published v1 contract ────────────────────────
export interface ApiTokenRow {
    id: number;
    name: string;
    abilities: string[];
    last_used_at?: string | null;
    expires_at?: string | null;
    expired: boolean;
    created_at?: string | null;
}

export interface ApiTokenIssued {
    token: string;
    name: string;
    abilities: string[];
    expires_at?: string | null;
}

export interface ApiSpecParam {
    name: string;
    in: 'path' | 'query';
    required?: boolean;
    description?: string;
    schema?: Record<string, unknown>;
}

export interface ApiSpecOperation {
    operationId?: string;
    summary: string;
    description?: string;
    tags?: string[];
    'x-required-permission'?: string;
    'x-required-ability'?: string;
    'x-middleware'?: string[];
    parameters?: ApiSpecParam[];
    requestBody?: { content?: { 'application/json'?: { schema?: Record<string, unknown> } } };
    responses?: Record<string, Record<string, unknown>>;
}

export interface ApiSpec {
    openapi: string;
    info: { title: string; version: string; summary?: string; description?: string };
    servers?: Array<{ url: string; description?: string }>;
    paths: Record<string, Record<string, ApiSpecOperation>>;
    components?: { schemas?: Record<string, Record<string, unknown>> };
}
