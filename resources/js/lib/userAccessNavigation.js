const sections = [
    { key: 'general', label: 'General', items: [
        { key: 'dashboard', label: 'Dashboard', href: '/dashboard', exact: true },
    ] },
    { key: 'inventory', label: 'Inventario', adminOnly: true, items: [
        { key: 'products_ml', label: 'Productos ML', href: '/producto' },
        { key: 'compare_ml', label: 'Comparar ML', href: '/ml/compare' },
        { key: 'tires', label: 'Catálogo individual', href: '/llantas' },
        { key: 'tire_comparator', label: 'Comparador catálogo', href: '/llantas/comparador' },
        { key: 'compound_products', label: 'Kits y combos', href: '/productos' },
        { key: 'price_rules', label: 'Fórmulas de ventas', href: '/price-rules' },
        { key: 'syscom', label: 'SYSCOM → ML', href: '/syscom-ml' },
        { key: 'syscom_orders', label: 'Pedidos SYSCOM', href: '/syscom-ml/pedidos' },
        { key: 'excel_import', label: 'Importar Excel', href: '/importar-excel' },
        { key: 'inventory_products', label: 'Almacén', href: '/almacen/productos' },
        { key: 'inventory_locations', label: 'Ubicaciones', href: '/almacen/ubicaciones' },
        { key: 'inventory_movements', label: 'Movimientos', href: '/almacen/movimientos' },
        { key: 'inventory_reservations', label: 'Reservas', href: '/almacen/reservas' },
    ] },
    { key: 'mercado_libre', label: 'Mercado Libre', items: [
        { key: 'meli_labels', label: 'Etiquetas Mercado Libre', href: '/mercado-libre/etiquetas' },
        { key: 'questions', label: 'Preguntas de productos', href: '/meli/preguntas', pendingQuestions: true },
        { key: 'messaging', label: 'Mensajería posventa', href: '/meli/mensajeria' },
        { key: 'claims', label: 'Reclamos', href: '/meli-claims' },
        { key: 'publications', label: 'Publicaciones Mercado Libre', href: '/meli/publicaciones' },
        { key: 'price_manager', label: 'Meli Price Manager', href: '/meli-price-manager', exact: true, adminOnly: true },
        { key: 'brands', label: 'Marcas y alias', href: '/meli-price-manager/brands', adminOnly: true },
        { key: 'scheduled_discounts', label: 'Promociones programadas', href: '/meli-price-manager/scheduled-discounts', adminOnly: true },
        { key: 'uncategorized', label: 'Pendientes de clasificación', href: '/meli-price-manager/uncategorized', adminOnly: true },
        { key: 'full_inventory', label: 'Inventario FULL', href: '/meli/full' },
        { key: 'full_shipments', label: 'Cajas 30 kg / Envíos FULL', href: '/meli/full/envios' },
        { key: 'full_pack_station', label: 'Mesa de Empaque (1 a 1)', href: '/meli/full/envios/empaque' },
    ] },
    { key: 'operations', label: 'Operaciones', items: [
        { key: 'ams_orders', label: 'AMS Pedidos', href: '/ams/pedidos', exact: true },
        { key: 'ams_process', label: 'AMS Procesar', href: '/ams/pedidos-procesar' },
        { key: 'ams_secondary', label: 'AMS Secundaria', href: '/ams/pedidos-secundaria' },
        { key: 'ams_tomorrow', label: 'AMS Mañana', href: '/ams/pedidos-manana' },
        { key: 'ams_incidencias', label: 'Incidencias de Productos', href: '/ams/incidencias' },
        { key: 'inventory_channels', label: 'Enlaces de canales', href: '/almacen/canales' },
        { key: 'inventory_meli_stock', label: 'Stock ML', href: '/almacen/canales/mercado-libre/stock' },
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
    const canOperate = isAdmin || role === 'operations'

    return sections
        .filter((section) => (section.key === 'system' ? isAdmin : (canOperate || !section.adminOnly)))
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => (section.key === 'system' ? isAdmin : (canOperate || !item.adminOnly))),
        }))
        .filter((section) => section.items.length > 0)
}
