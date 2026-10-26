<x-app-layout>

    <x-slot name="header">

        <h2 class="font-semibold text-xl text-gray-800 leading-tight">

            Registros sanitarios

        </h2>

    </x-slot>



    <div class="py-8">

        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-white shadow-sm sm:rounded-lg">

                <div class="p-6">
                    @if(session('status'))
                        <div role="status" class="mb-4 p-4 rounded bg-green-50 text-green-800">{{ session('status') }}</div>
                    @endif

                    <div class="mb-6">

                        <h3 class="text-lg font-semibold text-gray-900">

                            {{ auth()->user()->esAdministrador()

                                ? 'Todos los registros'

                                : 'Mis registros asignados' }}

                        </h3>



                        <p class="mt-1 text-sm text-gray-600">

                            Resultados: {{ $registros->total() }}

                        </p>

                    </div>



                    <form

                        method="GET"

                        action="{{ route('registros.index') }}"

                        class="flex flex-wrap items-end gap-3 mb-6"

                    >

                        <div class="flex-1 min-w-0">

                            <label

                                for="buscar"

                                class="block text-sm font-medium text-gray-700"

                            >

                                Buscar producto, RS/NSO, fabricante o país

                            </label>



                            <input

                                id="buscar"

                                name="buscar"

                                type="search"

                                maxlength="150"

                                value="{{ $buscar }}"

                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"

                            >



                            @error('buscar')

                                <p class="mt-1 text-sm text-red-600">

                                    {{ $message }}

                                </p>

                            @enderror

                        </div>



                        <x-primary-button>

                            Buscar

                        </x-primary-button>



                        <a

                            href="{{ route('registros.index') }}"

                            class="text-sm text-gray-600 underline"

                        >

                            Limpiar

                        </a>

                    </form>



                    <div class="overflow-x-auto">

                        <table class="min-w-full divide-y divide-gray-200">

                            <thead class="bg-gray-50">

                                <tr>

                                    @foreach ([

                                        'País',

                                        'Producto',

                                        'RS/NSO',

                                        'Vencimiento',

                                        'Estado informado',

                                        'Responsable', 'Acciones'

                                    ] as $columna)

                                        <th

                                            scope="col"

                                            class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"

                                        >

                                            {{ $columna }}

                                        </th>

                                    @endforeach

                                </tr>

                            </thead>



                            <tbody class="divide-y divide-gray-200">

                                @forelse ($registros as $registro)

                                    <tr>

                                        <td class="px-4 py-3 text-sm">

                                            {{ $registro->pais }}

                                        </td>



                                        <td class="px-4 py-3 text-sm">

                                            <p class="font-medium text-gray-900">

                                                {{ $registro->nombre_producto }}

                                            </p>

                                            <p class="text-gray-500">

                                                {{ $registro->fabricante }}

                                            </p>

                                        </td>



                                        <td class="px-4 py-3 text-sm">

                                            {{ $registro->rs_nso }}

                                        </td>



                                        <td class="px-4 py-3 text-sm whitespace-nowrap">

                                            {{ $registro->fecha_vencimiento->format('d/m/Y') }}

                                        </td>



                                        <td class="px-4 py-3 text-sm">

                                            {{ $registro->estado_actual ?: 'Sin información' }}

                                        </td>



                                        <td class="px-4 py-3 text-sm">

                                            {{ $registro->responsable?->name }}

                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            @can('update', $registro)
                                                <div class="flex items-center gap-2 whitespace-nowrap">
    <a
        href="{{ route('registros.edit', $registro) }}"
        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round"
            aria-hidden="true">
            <path d="m16 3 5 5-12 12-6 1 1-6L16 3Z" />
            <path d="m14 5 5 5" />
        </svg>
        Editar
    </a>

    <a
        href="{{ route('registros.edit', $registro) }}#historial"
        class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round"
            aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </svg>
        Historial
    </a>
</div>
                                            @endcan
                                        </td>

                                    </tr>

                                @empty

                                    <tr>

                                        <td

                                            colspan="7"

                                            class="px-4 py-10 text-center text-gray-500"

                                        >

                                            No hay registros disponibles

                                            para esta consulta.

                                        </td>

                                    </tr>

                                @endforelse

                            </tbody>

                        </table>

                    </div>



                    <div class="mt-6">

                        {{ $registros->links() }}

                    </div>

                </div>

            </div>

        </div>

    </div>

</x-app-layout>