<?php

namespace App\Http\Controllers;

use App\Models\Registro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RegistroController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Registro::class);

        $datos = $request->validate([
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        $buscar = trim($datos['buscar'] ?? '');

        $consulta = Registro::query()
            ->visiblesPara($request->user())
            ->with('responsable:id,name,email');

        if ($buscar !== '') {
            // Agrupar los OR evita que la búsqueda omita
            // el filtro del responsable.
            $consulta->where(function ($query) use ($buscar) {
                $query->where('nombre_producto', 'like', "%{$buscar}%")
                    ->orWhere('rs_nso', 'like', "%{$buscar}%")
                    ->orWhere('fabricante', 'like', "%{$buscar}%")
                    ->orWhere('pais', 'like', "%{$buscar}%");
            });
        }

        $registros = $consulta
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('registros.index', compact('registros', 'buscar'));
    }
}