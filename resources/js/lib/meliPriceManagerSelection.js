export function selectionScopeKey({ accountId, brandId, page, filters = {} }) {
    return JSON.stringify([
        accountId ?? null,
        brandId ?? null,
        page ?? 1,
        filters.search ?? '',
        filters.status ?? '',
        filters.category_id ?? '',
        filters.min_price ?? '',
        filters.max_price ?? '',
        filters.stock ?? 'all',
        filters.sync ?? 'all',
        filters.sort ?? 'title',
        filters.direction ?? 'asc',
        filters.per_page ?? 50,
    ])
}
