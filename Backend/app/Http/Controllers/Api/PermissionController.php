<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;

class PermissionController extends Controller
{
    
    public function __construct()
    {
        
        // Las rutas en web.php ya aplican 'auth' y 'ensure.role:super_admin'
        // Pero lo repetimos para seguridad si alguien monta rutas directas.
        $this->middleware('auth')->only([
            'permissions','roles','updateRolePermissions','updateUserPermissions','userPermissions'
        ]);
        // restrict to super_admin where corresponda (opcional)
        $this->middleware('ensure.role:super_admin')->only([
            'permissions','roles','updateRolePermissions','updateUserPermissions'
        ]);
    }

    /**
     * GET /api/v1/permissions
     * Devuelve arreglo plano de permisos (array), no wrapper.
     */
    public function permissions()
    {
        $perms = Permission::orderBy('name')->get(['id', 'name']);
        return response()->json($perms);
    }

    /**
     * GET /api/v1/roles
     * Devuelve roles con permisos asignados (ids + nombres).
     */
    public function roles()
    {
        $roles = Role::with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->map(fn($p) => ['id'=>$p->id,'name'=>$p->name])->values(),
                    'permission_ids' => $role->permissions->pluck('id')->values(),
                ];
            });

        return response()->json($roles);
    }

    /**
     * POST /api/v1/roles/{id}/permissions
     * Body: { permissions: [1,2,3], apply_to_existing_users: true|false (opcional) }
     *
     * Actualiza la tabla pivot role_permissions.
     * Opcionalmente puede propagar (apply_to_existing_users) a usuarios que tengan ese role
     * asignando/sincronizando permisos directos (normalmente es mejor usar herencia en runtime).
     */
    public function updateRolePermissions(Request $request, $id)
    {
        $data = $request->validate([
            'permissions' => ['nullable','array'],
            'permissions.*' => ['integer','exists:permissions,id'],
            'apply_to_existing_users' => ['sometimes','boolean'],
        ]);

        $role = Role::findOrFail($id);

        $permissionIds = $data['permissions'] ?? [];

        DB::transaction(function () use ($role, $permissionIds, $data) {
            // Sync role_permissions pivot
            $role->permissions()->sync($permissionIds);

            // Opcional: aplicar a usuarios existentes (no recomendado por defecto,
            // pero puede usarse). Esto da permisos directos en user_permissions.
            if (!empty($data['apply_to_existing_users'])) {
                $users = User::where('role_id', $role->id)->get();
                foreach ($users as $user) {
                    // aquí decidimos: sobrescribir sus permisos directos con los del rol
                    // o mezclarlos. Usaremos "merge": unir permisos del rol + permisos directos actuales.
                    $current = $user->permissions()->pluck('permissions.id')->toArray();
                    $merged = array_values(array_unique(array_merge($current, $permissionIds)));
                    $user->permissions()->sync($merged);
                }
            }
        });

        return response()->json(['ok' => true, 'role_id' => $role->id, 'permission_ids' => $permissionIds]);
    }

    /**
     * POST /api/v1/users/{id}/permissions
     * Body: { permissions: [1,2,3], replace: true|false (opcional) }
     *
     * Si replace=true -> se sincronizan exactamente los permisos dados.
     * Si replace=false (por defecto) -> se asignan solo los que vienen (merge).
     */
public function updateUserPermissions(Request $request, $id)
{
    $data = $request->validate([
        'permissions' => ['nullable','array'],
        'permissions.*' => ['integer','exists:permissions,id'],
    ]);

    $user = User::findOrFail($id);

    $permissionIds = $data['permissions'] ?? [];

    DB::transaction(function () use ($user, $permissionIds) {
        // 🔥 SIEMPRE REEMPLAZAR
        $user->permissions()->sync($permissionIds);
    });

    return response()->json([
        'ok' => true,
        'user_id' => $user->id,
        'permission_ids' => $permissionIds
    ]);
}

    /**
     * GET /api/v1/users/{id}/permissions  <-- ruta recomendada para frontend
     * Devuelve permisos heredados por rol, permisos directos y la lista "efectiva".
     */
public function userPermissions($id)
{
    $user = User::with([
    'permissions:permissions.id,permissions.name',
    'role.permissions:permissions.id,permissions.name',
    'role:roles.id,roles.name'
])->findOrFail($id);

    $userPerms = $user->permissions->map(fn($p) => [
        'id' => $p->id,
        'name' => $p->name
    ]);

    return response()->json([
        'user' => [
            'id' => $user->id,
            'name' => $user->name
        ],
        'role' => null, // ya no usamos roles
        'role_permissions' => [],
        'user_permissions' => $userPerms,
        'effective_permissions' => $userPerms, // 🔥 SOLO permisos directos
    ]);
}

public function usersWithPermissions(Request $request)
{
    $query = User::with([
        'role:id,name',
        'permissions:id,name',
        'role.permissions:id,name'
    ]);

    // 🔍 búsqueda opcional
    if ($request->search) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%$search%")
              ->orWhere('email', 'like', "%$search%");
        });
    }

    $users = $query->get()->map(function ($u) {

        $rolePerms = $u->role?->permissions ?? collect();
        $userPerms = $u->permissions ?? collect();

        // 🔥 permisos efectivos (sin duplicados)
        $effective = $rolePerms->merge($userPerms)
            ->unique('id')
            ->values();

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'role_id' => $u->role_id,

            'permissions' => $userPerms->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name
            ]),

            'effective_permissions' => $effective->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name
            ]),
        ];
    });

    return response()->json($users);
}
}