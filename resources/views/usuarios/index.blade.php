<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Administración de usuarios
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div role="status"
                    class="p-4 rounded-lg bg-green-50 text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div role="alert"
                    class="p-4 rounded-lg bg-red-50 text-red-700">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @foreach ($usuarios as $usuario)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-lg text-gray-900">
                                {{ $usuario->name }}
                            </h3>

                            <p class="text-sm text-gray-600">
                                {{ $usuario->email }}
                            </p>

                            <p class="mt-2 text-sm text-gray-600">
                                Registros asignados:
                                {{ $usuario->registros_count }}
                            </p>
                        </div>

                        <div class="text-sm">
                            <p>
                                {{ $usuario->esAdministrador()
                                    ? 'Administrador'
                                    : 'Responsable' }}
                            </p>

                            <p class="font-semibold">
                                {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                            </p>
                        </div>
                    </div>

                    @if ($usuario->role === 'responsable')
                        <form method="POST"
                            action="{{ route('usuarios.update', $usuario) }}"
                            class="mt-5 space-y-4">

                            @csrf
                            @method('PATCH')

                            <div>
                                <label for="activo-{{ $usuario->id }}"
                                    class="block text-sm font-medium text-gray-700">
                                    Estado de la cuenta
                                </label>

                                <select
                                    id="activo-{{ $usuario->id }}"
                                    name="activo"
                                    class="mt-1 rounded-md border-gray-300">
                                    <option value="0"
                                        @selected(! $usuario->activo)>
                                        Inactivo
                                    </option>
                                    <option value="1"
                                        @selected($usuario->activo)>
                                        Activo
                                    </option>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label
                                        for="password-{{ $usuario->id }}"
                                        class="block text-sm font-medium text-gray-700">
                                        Nueva contraseña
                                    </label>

                                    <input
                                        id="password-{{ $usuario->id }}"
                                        name="password"
                                        type="password"
                                        minlength="12"
                                        autocomplete="new-password"
                                        class="mt-1 block w-full rounded-md border-gray-300"
                                    >
                                </div>

                                <div>
                                    <label
                                        for="confirmation-{{ $usuario->id }}"
                                        class="block text-sm font-medium text-gray-700">
                                        Confirmar contraseña
                                    </label>

                                    <input
                                        id="confirmation-{{ $usuario->id }}"
                                        name="password_confirmation"
                                        type="password"
                                        minlength="12"
                                        autocomplete="new-password"
                                        class="mt-1 block w-full rounded-md border-gray-300"
                                    >
                                </div>
                            </div>

                            <p class="text-sm text-gray-500">
                                Mínimo 12 caracteres. Para activar una cuenta
                                inactiva debes asignarle una contraseña.
                                Si ya está activa, deja ambos campos vacíos
                                para conservar la actual.
                            </p>

                            <x-primary-button>
                                Guardar cambios
                            </x-primary-button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>