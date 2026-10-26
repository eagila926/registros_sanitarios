<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Editar registro sanitario</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm rounded-lg p-6">
            <a href="{{ route('registros.index') }}" class="text-indigo-700 underline">Volver al listado</a>
            <h3 class="text-lg font-semibold mt-4">{{ $registro->nombre_producto }}</h3>
            @if ($errors->any())
                <div role="alert" class="mt-4 p-4 bg-red-50 text-red-700 rounded"><ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul></div>
            @endif
            <form method="POST" action="{{ route('registros.update', $registro) }}" class="mt-6 space-y-6">
                @csrf @method('PATCH')
                <input type="hidden" name="version" value="{{ old('version', $version) }}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach (\App\Http\Controllers\RegistroEdicionController::CAMPOS as $campo=>$label)
                        <div>
                            <label for="{{ $campo }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                            @if (in_array($campo, ['presentacion','ruta_origen']))
                                <textarea id="{{ $campo }}" name="{{ $campo }}" rows="3" maxlength="10000" class="mt-1 block w-full rounded border-gray-300">{{ old($campo, $registro->$campo) }}</textarea>
                            @else
                                <input id="{{ $campo }}" name="{{ $campo }}"
                                    type="{{ $campo === 'fecha_vencimiento' ? 'date' : 'text' }}"
                                    value="{{ old($campo, $campo === 'fecha_vencimiento' ? $registro->fecha_vencimiento->format('Y-m-d') : $registro->$campo) }}"
                                    @required(in_array($campo,['pais','fabricante','nombre_producto','rs_nso','fecha_vencimiento']))
                                    class="mt-1 block w-full rounded border-gray-300">
                            @endif
                        </div>
                    @endforeach
                    <div>
                        <label for="responsable_id" class="block text-sm font-medium text-gray-700">Responsable</label>
                        <select id="responsable_id" name="responsable_id" required class="mt-1 block w-full rounded border-gray-300">
                            @foreach ($responsables as $responsable)
                                <option value="{{ $responsable->id }}"
                                    @selected((string)old('responsable_id', $registro->responsable_id) === (string)$responsable->id)
                                    @disabled(!$responsable->activo && (int)$responsable->id !== (int)$registro->responsable_id)>
                                    {{ $responsable->name }} - {{ $responsable->email }}{{ !$responsable->activo ? ' (inactivo)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @unless(auth()->user()->esAdministrador())
                            <p class="mt-2 text-sm text-amber-800">Si asignas otra persona, perderas el acceso al guardar.</p>
                        @endunless
                    </div>
                </div>
                <fieldset class="border-t pt-4">
                    <legend class="font-semibold">Destinatarios en copia</legend>
                    <p class="text-sm text-gray-600 mb-4">Separa varios correos con punto y coma (;). Estar en copia no concede acceso al registro.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach (\App\Http\Controllers\RegistroEdicionController::COPIAS as $tipo=>$label)
                            <div>
                                <label for="copia_{{ $tipo }}" class="block text-sm font-medium">{{ $label }}</label>
                                <textarea id="copia_{{ $tipo }}" name="copias[{{ $tipo }}]" rows="2" maxlength="5000" class="mt-1 block w-full rounded border-gray-300">{{ old('copias.'.$tipo, $copias[$tipo]) }}</textarea>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
                <x-primary-button>Guardar cambios</x-primary-button>
            </form>
        </div>
        <section id="historial" class="bg-white shadow-sm rounded-lg p-6 scroll-mt-6">
            <h3 class="text-lg font-semibold mb-4">Historial de cambios</h3>
            @forelse ($historial as $evento)
                <details class="border-b py-4">
                    <summary class="cursor-pointer font-medium">
                        {{ $evento->created_at->format('d/m/Y H:i:s') }} - {{ $evento->usuario?->name ?? 'Sistema' }} - {{ $evento->accion }}
                    </summary>
                    <div class="mt-3 overflow-x-auto"><table class="w-full text-sm text-left">
                        <thead><tr><th class="p-2">Campo</th><th class="p-2">Antes</th><th class="p-2">Despues</th></tr></thead>
                        <tbody>
                        @foreach (array_unique(array_merge(array_keys($evento->valores_anteriores ?? []), array_keys($evento->valores_nuevos ?? []))) as $campo)
                            @php
                                $antes = ($evento->valores_anteriores ?? [])[$campo] ?? null;
                                $despues = ($evento->valores_nuevos ?? [])[$campo] ?? null;
                                $etiqueta = \App\Http\Controllers\RegistroEdicionController::CAMPOS[$campo] ?? $campo;
                            @endphp
                            @if ($antes !== $despues)
                                <tr class="border-t align-top">
                                    <td class="p-2">{{ $etiqueta }}</td>
                                    <td class="p-2 whitespace-pre-wrap break-words">{{ is_array($antes) ? json_encode($antes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ($antes ?? '-') }}</td>
                                    <td class="p-2 whitespace-pre-wrap break-words">{{ is_array($despues) ? json_encode($despues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ($despues ?? '-') }}</td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table></div>
                </details>
            @empty
                <p class="text-gray-500">Sin cambios registrados.</p>
            @endforelse
            <div class="mt-4">{{ $historial->links() }}</div>
        </section>
    </div></div>
</x-app-layout>
