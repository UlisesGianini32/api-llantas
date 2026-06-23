<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased dark:bg-neutral-950 dark:text-slate-100">

    <div class="flex min-h-screen w-full">
        {{-- SIDEBAR --}}
        <flux:sidebar
            sticky
            stashable
            class="h-screen shrink-0 border-e border-slate-200 bg-white text-slate-700 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-200"
        >
            <flux:sidebar.toggle
                class="lg:hidden text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
                icon="x-mark"
            />

            {{-- LOGO / BRAND --}}
            <div class="mb-6">
                <a href="{{ route('dashboard') }}"
                   class="group flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 transition hover:border-indigo-300 hover:bg-white dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-indigo-500/40 dark:hover:bg-neutral-900">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white shadow-sm dark:bg-neutral-800">
                        <img
                            src="{{ asset('logo-llantas.png') }}"
                            alt="Llantas"
                            class="h-8 w-8 object-contain"
                        >
                    </div>

                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                            Llantas
                        </p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                            Panel administrativo
                        </p>
                    </div>
                </a>
            </div>

            {{-- GENERAL --}}
            <div class="mb-5">
                <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    General
                </div>

                <flux:navlist variant="outline" class="space-y-1">
                    <flux:navlist.item
                        icon="home"
                        :href="route('dashboard')"
                        :current="request()->routeIs('dashboard')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Dashboard
                    </flux:navlist.item>
                </flux:navlist>
            </div>

            {{-- INVENTARIO --}}
            <div class="mb-5">
                <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    Inventario
                </div>

                <flux:navlist variant="outline" class="space-y-1">
                    <flux:navlist.item
                        icon="shopping-bag"
                        :href="route('producto.index')"
                        :current="request()->routeIs('producto.*')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Productos ML
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="arrows-right-left"
                        :href="route('ml.compare')"
                        :current="request()->routeIs('ml.compare')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Compare
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="archive-box"
                        :href="route('llantas.index')"
                        :current="request()->routeIs('llantas.index') || request()->routeIs('llantas.edit')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Llantas
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="squares-2x2"
                        :href="route('productos.index')"
                        :current="request()->routeIs('productos.*')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Productos compuestos
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="calculator"
                        :href="route('price_rules.index')"
                        :current="request()->routeIs('price_rules.*')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Fórmulas de ventas
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="document-arrow-up"
                        :href="route('excel.vista')"
                        :current="request()->routeIs('excel.*')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-emerald-200 data-[current=true]:bg-emerald-50 data-[current=true]:text-emerald-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-emerald-500/30 dark:data-[current=true]:bg-emerald-500/15 dark:data-[current=true]:text-emerald-300"
                    >
                        Importar Excel
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="exclamation-triangle"
                        :href="route('llantas.agotadas')"
                        :current="request()->routeIs('llantas.agotadas')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-red-50 hover:text-red-700 data-[current=true]:border data-[current=true]:border-red-200 data-[current=true]:bg-red-50 data-[current=true]:text-red-700 dark:text-slate-300 dark:hover:bg-red-500/10 dark:hover:text-red-300 dark:data-[current=true]:border dark:data-[current=true]:border-red-500/30 dark:data-[current=true]:bg-red-500/15 dark:data-[current=true]:text-red-300"
                    >
                        Llantas agotadas
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="clock"
                        :href="route('llantas.no_actualizadas')"
                        :current="request()->routeIs('llantas.no_actualizadas')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        Llantas no actualizadas
                    </flux:navlist.item>
                </flux:navlist>
            </div>

            {{-- OPERACIONES --}}
            <div class="mb-5">
                <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    Operaciones
                </div>

                <flux:navlist variant="outline" class="space-y-1">
                    <flux:navlist.item
                        icon="truck"
                        :href="route('ams.pedidos.index')"
                        :current="request()->routeIs('ams.pedidos.index')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 data-[current=true]:border data-[current=true]:border-indigo-200 data-[current=true]:bg-indigo-50 data-[current=true]:text-indigo-700 dark:text-slate-300 dark:hover:bg-neutral-900 dark:hover:text-white dark:data-[current=true]:border dark:data-[current=true]:border-indigo-500/30 dark:data-[current=true]:bg-indigo-500/15 dark:data-[current=true]:text-indigo-300"
                    >
                        AMS Pedidos
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="clipboard-document-check"
                        :href="route('ams.pedidos.procesar')"
                        :current="request()->routeIs('ams.pedidos.procesar')"
                        wire:navigate
                        class="rounded-xl text-slate-600 hover:bg-amber-50 hover:text-amber-700 data-[current=true]:border data-[current=true]:border-amber-200 data-[current=true]:bg-amber-50 data-[current=true]:text-amber-700 dark:text-slate-300 dark:hover:bg-amber-500/10 dark:hover:text-amber-300 dark:data-[current=true]:border dark:data-[current=true]:border-amber-500/30 dark:data-[current=true]:bg-amber-500/15 dark:data-[current=true]:text-amber-300"
                    >
                        AMS Procesar
                    </flux:navlist.item>
                </flux:navlist>
            </div>

            <div class="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Estado del panel
                </p>
                <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                    Sistema de llantas
                </p>
                <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
                    Administra inventario, productos compuestos, AMS y procesos de Mercado Libre desde un solo lugar.
                </p>
            </div>

            <flux:spacer />

            <div class="border-t border-slate-200 pt-4 dark:border-neutral-800">
                <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-left transition hover:bg-white dark:border-neutral-800 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-sm font-bold text-white">
                            {{ auth()->user()->initials() }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                                {{ auth()->user()->name }}
                            </div>
                            <div class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ auth()->user()->email }}
                            </div>
                        </div>
                    </button>

                    <flux:menu class="w-[240px] rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="rounded-xl px-3 py-2">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-200 text-sm font-bold text-slate-700 dark:bg-neutral-800 dark:text-white">
                                    {{ auth()->user()->initials() }}
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-slate-900 dark:text-white">
                                        {{ auth()->user()->name }}
                                    </div>
                                    <div class="truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ auth()->user()->email }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <flux:menu.separator />

                        <flux:menu.item :href="route('profile.edit')" icon="cog">
                            Configuración
                        </flux:menu.item>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">
                                Cerrar sesión
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </flux:sidebar>

        {{-- CONTENIDO --}}
        <main class="min-w-0 flex-1">
            {{ $slot }}
        </main>
    </div>

    @fluxScripts
</body>
</html>