<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\Category;
use App\Models\Comisiones\CategoryCommissionTier;
use App\Models\Comisiones\CategoryTierCommission;
use Illuminate\Http\Request;

class CommissionCategoryController extends Controller
{
    /**
     * GET /api/v1/commissions/categories?role_id=2
     */
    public function index(Request $request)
    {
        $roleId = $request->query('role_id');

        $categories = Category::orderBy('name')->get();

        $result = [];

        foreach ($categories as $category) {

            $tiers = CategoryCommissionTier::where('category_id', $category->id)
                ->orderBy('sort_order')
                ->get();

            // 🔹 Si no existen tiers → crear estructura vacía (3 rangos)
            if ($tiers->isEmpty()) {
                $tiersArr = [
                    $this->emptyTier('0 - 99.9%'),
                    $this->emptyTier('100 - 119.9%'),
                    $this->emptyTier('120 - 200%'),
                ];
            } else {
                $tiersArr = [];

                foreach ($tiers as $tier) {
                    $commissions = CategoryTierCommission::where('tier_id', $tier->id)
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->role_id => $c->commission_percentage])
                        ->toArray();

                    $tiersArr[] = [
                        'id' => $tier->id,
                        'label' => $tier->label,
                        'min_pct' => $tier->min_pct,
                        'max_pct' => $tier->max_pct,
                        'commissions' => $commissions
                    ];
                }
            }

            $result[] = [
                'category_id' => $category->id,
                'name' => $category->name,
                'code' => $category->code,
                'tiers' => $tiersArr
            ];
        }

        return response()->json([
            'categories' => $result
        ]);
    }

    private function emptyTier(string $label): array
    {
        return [
            'id' => null,
            'label' => $label,
            'min_pct' => null,
            'max_pct' => null,
            'commissions' => []
        ];
    }
}
