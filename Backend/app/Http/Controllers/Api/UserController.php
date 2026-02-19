<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    // Roles permitidos para crear/editar
    protected array $allowedRoles = ['seller', 'cashier', 'adminpresupuesto'];

    /**
     * Devuelve usuarios que pertenecen exclusivamente a los roles permitidos.
     * GET /api/v1/manage/users?search=...
     */
public function indexForManagedRoles(Request $request)
{
    $allowedRoles = ['seller','cashier','adminpresupuesto'];

    $query = User::whereIn('role', $allowedRoles);

    if ($request->search) {
        $search = $request->search;
        $query->where(function($q) use ($search){
            $q->where('name','like',"%$search%")
              ->orWhere('email','like',"%$search%")
              ->orWhere('username','like',"%$search%");
        });
    }

    return response()->json(
        $query->orderBy('name')->paginate(50)
    );
}


    /**
     * Crear un usuario — solo permite asignar roles dentro de allowedRoles.
     * POST /api/v1/manage/users
     */public function storeManagedUser(Request $request)
{
    $allowedRoles = ['seller','cashier','adminpresupuesto'];

    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|min:8',
        'role' => ['required', Rule::in($allowedRoles)],
        'username' => 'nullable|string|max:255',
        'seller_code' => 'nullable|string|max:50',
    ]);

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'username' => $request->username,
        'password' => Hash::make($request->password),
        'role' => $request->role,
        'seller_code' => $request->seller_code,
    ]);

    return response()->json([
        'message' => 'Usuario creado correctamente',
        'user' => $user
    ], 201);
}

    /**
     * Actualizar usuario (PUT) — puede actualizar contraseña opcionalmente.
     * PUT /api/v1/manage/users/{id}
     */
public function updateManagedUser(Request $request, $id)
{
    $allowedRoles = ['seller','cashier','adminpresupuesto'];

    $user = User::findOrFail($id);

    $request->validate([
        'name' => 'sometimes|required|string|max:255',
        'email' => ['sometimes','required','email', Rule::unique('users')->ignore($user->id)],
        'password' => 'nullable|min:8',
        'role' => ['sometimes','required', Rule::in($allowedRoles)],
        'seller_code' => 'nullable|string|max:50',
    ]);

    if ($request->has('name')) $user->name = $request->name;
    if ($request->has('email')) $user->email = $request->email;
    if ($request->has('username')) $user->username = $request->username;
    if ($request->has('role')) $user->role = $request->role;
    if ($request->has('seller_code')) $user->seller_code = $request->seller_code;

    if ($request->filled('password')) {
        $user->password = Hash::make($request->password);
    }

    $user->save();

    return response()->json([
        'message' => 'Usuario actualizado correctamente',
        'user' => $user
    ]);
}

}
