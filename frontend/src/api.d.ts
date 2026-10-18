export function api<T = unknown>(path: string, options?: { method?: string; body?: unknown; headers?: Record<string, string>; signal?: AbortSignal }): Promise<T>
export function downloadFile(file: { id: number; name: string }): Promise<void>
