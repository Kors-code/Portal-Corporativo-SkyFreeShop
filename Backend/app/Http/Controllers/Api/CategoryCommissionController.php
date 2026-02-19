<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comisiones\Category;
use App\Models\Comisiones\CategoryCommission;
use App\Models\Comisiones\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryCommissionController extends Controller
{
    // List categories with commission (optionally filter by role_id)
    public function index(Request $request)
    {
        $roleId = $request->query('role_id');

        $budget_id = $request->query('budget_id');
        
        $CODIGOS_OMITIR = ['10','11','12','98'];

        $categories = Category::on('budget')
            ->select('id','classification_code as code','name','description')
            ->orderBy('name')
            ->get()
            ->reject(function ($c) use ($CODIGOS_OMITIR) {
                return in_array((string)$c->code, $CODIGOS_OMITIR);
            })
            ->values();

        // load commissions for those categories for role (if provided) in one query
        $commissions = collect();

        if ($roleId) {
            $query = CategoryCommission::on('budget')
                ->whereIn('category_id', $categories->pluck('id'))
                ->where('role_id', $roleId);
        
            if ($budget_id) {
                $query->where('budget_id', $budget_id);
            }
        
            $commissions = $query->get()->keyBy('category_id');
        }



        $payload = $categories->map(function($c) use ($commissions) {
            
            $r = $commissions[$c->id] ?? null;
           return [
                'category_id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'description' => $c->description,
                'commission_id' => $r ? $r->id : null,
                'commission_percentage' => $r ? (float)$r->commission_percentage : null,
                'commission_percentage100' => $r ? (float)$r->commission_percentage100 : null,
                'commission_percentage120' => $r ? (float)$r->commission_percentage120 : null,
                'participation_pct' => $r ? (float)$r->participation_pct : null,
                'budget_id' => $r ? (float)$r->budget_id : null,
            ];

        });

        return response()->json(['categories' => $payload]);
    }

    // Upsert a commission for category + role
    public function upsert(Request $request)
    {
        $data = $request->validate([
        'category_id' => ['required','integer','exists:budget.categories,id'],
        'role_id' => ['required','integer','exists:budget.roles,id'],
        'commission_percentage' => ['nullable','numeric','min:0'],
        'commission_percentage100' => ['nullable','numeric','min:0'],
        'commission_percentage120' => ['nullable','numeric','min:0'],
        'participation_pct' => ['nullable','numeric','min:0','max:100'],
        'budget_id' => ['nullable','integer','exists:budget.budgets,id'],

    ]);


        DB::beginTransaction();
        try {
                $row = CategoryCommission::on('budget')->updateOrCreate(
            [
                'category_id' => $data['category_id'],
                'role_id' => $data['role_id'],
                'budget_id' => $data['budget_id'] ?? null
            ],
            [
                'commission_percentage' => $data['commission_percentage'] ?? 0,
                'commission_percentage100' => $data['commission_percentage100'] ?? 0,
                'commission_percentage120' => $data['commission_percentage120'] ?? 0,
                'participation_pct' => $data['participation_pct'] ?? 10,
            ]
        );



            DB::commit();
            return response()->json(['commission' => $row]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>'Error saving commission','error'=>$e->getMessage()],500);
        }
    }

    // Delete commission config by id
    public function destroy($id)
    {
        $row = CategoryCommission::on('budget')->find($id);
        if (!$row) return response()->json(['message'=>'Not found'],404);
        $row->delete();
        return response()->json(['message'=>'Deleted']);
    }

    // Optional: bulk update (array of {category_id, commission_percentage})
public function bulkUpdate(Request $request)
{
    $payload = $request->validate([
        'role_id' => ['required','integer','exists:budget.roles,id'],
        'items' => ['required','array'],
        'items.*.category_id' => ['required','integer','exists:budget.categories,id'],
        'items.*.commission_percentage' => ['nullable','numeric','min:0'],
        'items.*.commission_percentage100' => ['nullable','numeric','min:0'],
        'items.*.commission_percentage120' => ['nullable','numeric','min:0'],
        'items.*.participation_pct' => ['nullable','numeric','min:0','max:100'],
        'items.*.budget_id' => ['nullable','integer','exists:budget.budgets,id'],
    ]);

    DB::beginTransaction();

    foreach ($payload['items'] as $it) {
        CategoryCommission::on('budget')->updateOrCreate(
        [
            'category_id' => $it['category_id'],
            'role_id' => $payload['role_id'],
            'budget_id' => $it['budget_id'] ?? null
        ],
        [
            'commission_percentage' => $it['commission_percentage'] ?? 0,
            'commission_percentage100' => $it['commission_percentage100'] ?? 0,
            'commission_percentage120' => $it['commission_percentage120'] ?? 0,
            'participation_pct' => $it['participation_pct'] ?? 10,
        ]
    );

    }

    DB::commit();

    return response()->json(['message' => 'Bulk saved']);
}

}
