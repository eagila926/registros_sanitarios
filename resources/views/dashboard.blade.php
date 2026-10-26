<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Registros Sanitarios Escollanos
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold">
                        Bienvenido, {{ auth()->user()->name }}
                    </h3>

                    <p class="mt-2 text-gray-600">
                        {{ auth()->user()->email }}
                    </p>

                    <p class="mt-4">
                        Rol:
                        <strong>
                            {{ auth()->user()->esAdministrador()
                                ? 'Administrador'
                                : 'Responsable' }}
                        </strong>
                    </p>

                    <p class="mt-4 text-gray-600">
                        @if (auth()->user()->esAdministrador())
                            Podrás administrar usuarios y consultar
                            todos los registros sanitarios.
                        @else
                            Podrás consultar y editar únicamente
                            los registros asignados a tu cuenta.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>