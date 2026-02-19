<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comisiones\Category;
use App\Models\Comisiones\Role;
use App\Models\Comisiones\CategoryCommission;
use App\Models\Comisiones\UserCategoryCommission;
use App\Models\Comisiones\User;

class CommissionConfigController extends Controller
{
    // obtener datos para la UI: categorias, roles y reglas existentes
    public function index()
    {
        $categories = Category::orderBy('id')->get();
        $roles = Role::orderBy('name')->get();
        $rules = CategoryCommission::get();
        $userOverrides = UserCategoryCommission::with(['user','category'])->get();

        return response()->json([
            'categories' => $categories,
            'roles' => $roles,
            'rules' => $rules,
            'user_overrides' => $userOverrides
        ]);
    }

    // crear/actualizar regla category_commissions (category_id + role_id)
    public function upsertCategoryRule(Request $r)
    {
        $r->validate([
            'category_id'=>'required|integer',
            'role_id'=>'required|integer',
            'commission_percentage'=>'required|numeric|min:0'
        ]);

        $rule = CategoryCommission::updateOrCreate(
            ['category_id'=>$r->category_id, 'role_id'=>$r->role_id],
            ['commission_percentage'=>$r->commission_percentage]
        );

        return response()->json(['rule'=>$rule]);
    }

    // crear/actualizar override por usuario y categoria
    public function upsertUserOverride(Request $r)
    {
        $r->validate([
            'user_id' => 'required|integer',
            'category_id' => 'required|integer',
            'commission_percentage' => 'required|numeric|min:0'
        ]);

        $ov = UserCategoryCommission::updateOrCreate(
            ['user_id'=>$r->user_id,'category_id'=>$r->category_id],
            ['commission_percentage'=>$r->commission_percentage,'active'=>true]
        );

        return response()->json(['override'=>$ov]);
    }

    public function deleteUserOverride($id)
    {
        $ov = UserCategoryCommission::findOrFail($id);
        $ov->delete();
        return response()->json(['deleted' => $id]);
    }
}
