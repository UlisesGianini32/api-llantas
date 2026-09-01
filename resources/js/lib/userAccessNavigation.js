const sections = [
    { key: 'general', label: 'General', items: [
        { key: 'dashboard', label: 'Dashboard', href: '/dashboard', exact: true },
    ] },
    { key: 'inventory', label: 'Inventario', adminOnly: true, items: [
        { key: 'products_ml', label: 'Productos ML', href: '/producto' },
        { key: 'compare_ml', label: 'Comparar ML', href: '/ml/compare' },
        { key: 'tires', label: 'Llantas', href: '/llantas' },
        { key: 'compound_products', label: 'Productos compuestos', href: '/productos' },
        { key: 'price_rules', label: 'Fórmulas de ventas', href: '/price-rules' },
        { key: 'syscom', label: 'SYSCOM → ML', href: '/syscom-ml' },
        { key: 'excel_import', label: 'Importar Excel', href: '/importar-excel' },
        { key: 'out_of_stock', label: 'Llantas agotadas', href: '/llantas/agotadas', danger: true },
        { key: 'stale_tires', label: 'Llantas no actualizadas', href: '/llantas/no-actualizadas' },
    ] },
    { key: 'mercado_libre', label: 'Mercado Libre', items: [
        { key: 'questions', label: 'Preguntas de productos', href: '/meli/preguntas', pendingQuestions: true },
        { key: 'messaging', label: 'Mensajería posventa', href: '/meli/mensajeria' },
        { key: 'claims', label: 'Reclamos', href: '/meli-claims' },
        { key: 'publications', label: 'Publicaciones Mercado Libre', href: '/meli/publicaciones' },
        { key: 'price_manager', label: 'Meli Price Manager', href: '/meli-price-manager', exact: true, adminOnly: true },
        { key: 'brands', label: 'Marcas y alias', href: '/meli-price-manager/brands', adminOnly: true },
        { key: 'uncategorized', label: 'Pendientes de clasificación', href: '/meli-price-manager/uncategorized', adminOnly: true },
        { key: 'full_inventory', label: 'Inventario FULL', href: '/meli/full' },
    ] },
    { key: 'operations', label: 'Operaciones', items: [
        { key: 'ams_orders', label: 'AMS Pedidos', href: '/ams/pedidos', exact: true },
        { key: 'ams_process', label: 'AMS Procesar', href: '/ams/pedidos-procesar' },
        { key: 'ams_secondary', label: 'AMS Secundaria', href: '/ams/pedidos-secundaria' },
        { key: 'ams_tomorrow', label: 'AMS Mañana', href: '/ams/pedidos-manana' },
    ] },
    { key: 'system', label: 'Sistema', adminOnly: true, items: [
        { key: 'health', label: 'Estado del sistema', href: '/sistema/estado' },
        { key: 'queues', label: 'Colas', href: '/sistema/colas' },
        { key: 'logs', label: 'Logs', href: '/sistema/logs' },
        { key: 'actions', label: 'Acciones rápidas', href: '/sistema/acciones' },
    ] },
]

export function sidebarSectionsForRole(role) {
    const isAdmin = role === 'admin'

    return sections
        .filter((section) => isAdmin || !section.adminOnly)
        .map((section) => ({ ...section, items: section.items.filter((item) => isAdmin || !item.adminOnly) }))
        .filter((section) => section.items.length > 0)
}
