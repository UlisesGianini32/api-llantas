<x-layouts.app title="Importar Excel">

<div class="max-w-xl mx-auto space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
        Importar inventario desde Excel
    </h1>

    {{-- MENSAJES --}}
    @if(session('success'))
        <div class="rounded-lg bg-green-50 border border-green-200 text-green-700 px-4 py-3 dark:bg-green-900/30 dark:border-green-700 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 dark:bg-red-900/30 dark:border-red-700 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    {{-- FORM --}}
    <form
        method="POST"
        action="{{ route('excel.importar') }}"
        enctype="multipart/form-data"
        class="space-y-4 rounded-xl p-6 border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-800"
    >
        @csrf

        <div>
            <label class="block text-sm text-zinc-600 dark:text-gray-400 mb-1">
                Archivo Excel (.xlsx)
            </label>

            <input
                type="file"
                name="archivo"
                required
                class="block w-full text-sm
                       text-zinc-700 dark:text-gray-300

                       file:cursor-pointer
                       file:rounded-md file:px-4 file:py-2
                       file:border file:border-zinc-300
                       file:bg-zinc-100 file:text-zinc-900
                       hover:file:bg-zinc-200

                       dark:file:border-neutral-700
                       dark:file:bg-neutral-800 dark:file:text-white
                       dark:hover:file:bg-neutral-700"
            >
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('dashboard') }}"
               class="px-4 py-2 rounded-md border border-zinc-300 bg-white text-zinc-900 hover:bg-zinc-100
                      dark:bg-neutral-800 dark:text-gray-300 dark:border-neutral-700 dark:hover:bg-neutral-700">
                Cancelar
            </a>

            <button
                type="submit"
                class="px-5 py-2 rounded-md bg-indigo-600 text-white hover:bg-indigo-500 font-semibold"
            >
                ⬆ Importar
            </button>
        </div>
    </form>

    {{-- INFO --}}
    <div class="text-sm text-zinc-600 dark:text-gray-500">
        <p>• El archivo debe contener las columnas esperadas.</p>
        <p>• Las llantas nuevas se crearán automáticamente.</p>
        <p>• Los productos compuestos se generan solos.</p>
    </div>

</div>

</x-layouts.app>
