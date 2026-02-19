<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\Role;

class RoleController extends Controller
{
    public function index()
    {
        // devolvemos array simple de roles: [{id, name}, ...]
        $roles = Role::select('id','name')->orderBy('name')->get();
        return response()->json($roles);
    }
}
