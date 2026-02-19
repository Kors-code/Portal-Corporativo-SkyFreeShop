<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Personal\WishItem;
use App\Models\Personal\CatalogProduct;
use Illuminate\Validation\Rule;
use App\Models\Personal\WishItemSelection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;


class WishItemController extends Controller
{
    
    
public function selectionsList(Request $request)
{
    $start = $request->query('start');
    $end = $request->query('end');
    $category = $request->query('category');
    $export = $request->query('export'); // export=csv
    $sep = $request->query('sep', 'comma'); // 'comma' (por defecto) o 'semicolon' o 'tab'

    $delimiter = ',';
    if ($sep === 'semicolon') $delimiter = ';';
    if ($sep === 'tab') $delimiter = "\t";

    $q = DB::connection('mysql_personal')->table('wish_item_selections as s')
        ->leftJoin('wish_items as w', 's.wish_item_id', '=', 'w.id')
        ->leftJoin('catalog_products as c', 's.catalog_product_id', '=', 'c.id')
        ->select(
            's.id as selection_id',
            's.wish_item_id',
            's.user_id as reported_by_id',
            's.meta',
            's.created_at as selection_created_at',
            'w.product_text as wish_text',
            'w.category as wish_category',
            'w.catalog_product_id as wish_catalog_product_id',
            'w.indicator as wish_indicator',
            'c.sku as catalog_sku',
            'c.product as catalog_product',
            'c.price_sale as catalog_price_sale',
            'c.category as catalog_category'
        );

    if ($start) $q->where('s.created_at', '>=', Carbon::parse($start)->startOfDay());
    if ($end) $q->where('s.created_at', '<=', Carbon::parse($end)->endOfDay());
    if ($category) {
        $q->where(function($qq) use ($category) {
            $qq->where('w.category', $category)->orWhere('c.category', $category);
        });
    }

    $rows = $q->orderBy('s.created_at','desc')->limit(5000)->get();

    // obtener nombres de usuarios involucrados (tabla users en connection default)
    $userIds = $rows->pluck('reported_by_id')->filter()->unique()->values()->all();
    $users = [];
    if (!empty($userIds)) {
        $users = DB::table('users')->whereIn('id', $userIds)->pluck('name','id')->toArray();
    }

    $result = $rows->map(function($r) use ($users) {
        return [
            'selection_id' => $r->selection_id,
            'wish_item_id' => $r->wish_item_id,
            'wish_text' => $r->wish_text,
            'category' => $r->wish_category ?? $r->catalog_category,
            'catalog_product_sku' => $r->catalog_sku,
            'catalog_product' => $r->catalog_product,
            'catalog_price_sale' => $r->catalog_price_sale,
            'reported_by' => $r->reported_by_id ? ['id' => $r->reported_by_id, 'name' => ($users[$r->reported_by_id] ?? null)] : null,
            'meta' => $r->meta,
            'created_at' => $r->selection_created_at,
        ];
    })->values();

    if ($export === 'csv') {
        $filename = 'informe_selections_' . ($start ?? 'all') . '_' . ($end ?? 'all') . '.csv';

        return response()->streamDownload(function() use ($result, $delimiter) {
            // BOM UTF-8 para que Excel reconozca correctamente UTF-8
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');

            // Headers fijos (orden)
            $headers = [
                'Selection ID',
                'Wish ID',
                'Texto',
                'Categoría',
                'SKU',
                'Producto catálogo',
                'Precio venta',
                'Reportó (ID)',
                'Reportó (Nombre)',
                'Meta',
                'Fecha'
            ];

            // Si usamos tab, fputcsv con "\t"
            fputcsv($out, $headers, $delimiter);

            foreach ($result as $row) {
                $line = [
                    $row['selection_id'],
                    $row['wish_item_id'] ?? '',
                    // eliminar saltos de linea para evitar celdas rotas
                    is_string($row['wish_text']) ? str_replace(["\r","\n"], [' ',' '], $row['wish_text']) : $row['wish_text'],
                    $row['category'] ?? '',
                    $row['catalog_product_sku'] ?? '',
                    is_string($row['catalog_product']) ? str_replace(["\r","\n"], [' ',' '], $row['catalog_product']) : $row['catalog_product'],
                    $row['catalog_price_sale'] ?? '',
                    $row['reported_by']['id'] ?? '',
                    $row['reported_by']['name'] ?? '',
                    is_string($row['meta']) ? str_replace(["\r","\n"], [' ',' '], $row['meta']) : $row['meta'],
                    $row['created_at'] ?? '',
                ];

                fputcsv($out, $line, $delimiter);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8'
        ]);
    }

    return response()->json($result->toArray());
}

    
         public function me()
    {
        $user = auth()->user();
    
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }
    
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ]);
    }

    // GET /api/v1/catalog/categories
    public function categories()
    {
        
        
        $cats = DB::connection('mysql_personal')
            ->table('catalog_products')
            ->select('category')
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(fn($c) => ['id' => crc32($c), 'name' => $c]); // id sintético si no quieres usar texto puro

        return response()->json($cats);
    }

    // GET /api/v1/catalog-products
    // params: q (opcional), category (opcional)
    public function searchCatalog(Request $request)
    {
        $q = $request->query('q');
        $category = $request->query('category');

        $query = DB::connection('mysql_personal')->table('catalog_products');

        if ($category) {
            $query->where('category', $category);
        }

        if ($q) {
            $qlike = '%' . trim($q) . '%';
            $query->where(function($qbd) use ($qlike) {
                $qbd->where('product', 'like', $qlike)
                    ->orWhere('sku', 'like', $qlike)
                    ->orWhere('brand', 'like', $qlike)
                    ->orWhere('supplier', 'like', $qlike);
            });
        }

        $res = $query->select('id', 'sku', 'product', 'brand', 'category', 'supplier', 'price_sale')->limit(100)->get();

        return response()->json($res);
    }

    // GET /api/v1/wish-items
    // params: category, q
// GET /api/v1/wish-items
public function listWishItems(Request $request)
{
    $q = $request->query('q');
    $category = $request->query('category');
    $start = $request->query('start');
    $end = $request->query('end');

    $query = DB::connection('mysql_personal')
        ->table('wish_items as w')
        ->leftJoin('catalog_products as c', 'w.catalog_product_id', '=', 'c.id')
        ->select(
            'w.*',
            'c.id as c_id',
            'c.sku as c_sku',
            'c.product as c_product',
            'c.price_sale as c_price_sale',
            'c.category as c_category'
        );

    // filtro por categoría (wish o catálogo)
    if ($category) {
        $query->where(function($qq) use ($category) {
            $qq->where('w.category', $category)
               ->orWhere('c.category', $category);
        });
    }

    // texto libre
    if ($q) {
        $query->where('w.product_text', 'like', '%' . $q . '%');
    }

    // Si el usuario envió start/end filtramos wish_items que tengan selecciones en ese rango.
    // Esto evita duplicados y mantiene coherencia con /wish-items/stats
    if ($start || $end) {
        $query->whereExists(function($sub) use ($start, $end) {
            $sub->select(DB::raw(1))
                ->from('wish_item_selections as s2')
                ->whereRaw('s2.wish_item_id = w.id');

            if ($start) {
                $sub->where('s2.created_at', '>=', Carbon::parse($start)->startOfDay());
            }
            if ($end) {
                $sub->where('s2.created_at', '<=', Carbon::parse($end)->endOfDay());
            }
        });
    }

    $rows = $query
        ->orderBy('w.created_at', 'desc')
        ->limit(1000)
        ->get();

    // obtener último user_id por wish (map wish_id => user_id) (igual que antes)
    $wishIds = $rows->pluck('id')->toArray();
    $lastBy = [];
    if (!empty($wishIds)) {
        $lasts = DB::connection('mysql_personal')
            ->table('wish_item_selections')
            ->select('wish_item_id', DB::raw('MAX(created_at) as last_at'))
            ->whereIn('wish_item_id', $wishIds)
            ->groupBy('wish_item_id')
            ->get()
            ->pluck('last_at', 'wish_item_id')
            ->toArray();

        if (!empty($lasts)) {
            $userMap = DB::connection('mysql_personal')
                ->table('wish_item_selections as s')
                ->select('s.wish_item_id','s.user_id')
                ->whereIn('s.wish_item_id', array_keys($lasts))
                ->whereIn('s.created_at', array_values($lasts))
                ->get()
                ->pluck('user_id','wish_item_id')
                ->toArray();
            $lastBy = $userMap;
        }
    }

    // nombres de usuarios desde tabla users (app DB)
    $userIds = array_values($lastBy);
    $users = [];
    if (!empty($userIds)) {
        $users = DB::table('users')->whereIn('id', $userIds)->pluck('name','id')->toArray();
    }

    $items = $rows->map(function ($row) use ($lastBy, $users) {
        $count = (int) ($row->count ?? 0);
        $price = (float) ($row->c_price_sale ?? 0);
        $ventaPerdida = round($count * $price, 2);

        $lastUserId = $lastBy[$row->id] ?? null;
        $lastUserName = $lastUserId ? ($users[$lastUserId] ?? null) : null;

        return [
            'id' => $row->id,
            'product_text' => $row->product_text,
            'category' => $row->category,
            'catalog_product_id' => $row->catalog_product_id,
            'indicator' => $row->indicator,
            'count' => $row->count,
            'created_at' => $row->created_at,
            'venta_perdida' => $ventaPerdida,
            'last_reported_by' => $lastUserId ? ['id' => $lastUserId, 'name' => $lastUserName] : null,
            'catalog_product' => $row->catalog_product_id ? [
                'id' => $row->c_id,
                'sku' => $row->c_sku,
                'product' => $row->c_product,
                'price_sale' => $row->c_price_sale,
                'category' => $row->c_category,
            ] : null
        ];
    });

    return response()->json($items);
}




    // POST /api/v1/wish-items
    public function create(Request $request)
    {
        $data = $request->validate([
            'product_text' => 'required|string|max:1024',
            'category' => 'nullable|string|max:255',
            'catalog_product_id' => 'nullable|integer|exists:mysql_personal.catalog_products,id',
            'user_id' => 'nullable|integer',
        ]);

        // si no viene catalog_product_id intentamos buscar coincidencia exacta/simple
        $catalogProductId = $data['catalog_product_id'] ?? null;
        if (!$catalogProductId) {
            $q = $data['product_text'];
            if (!empty($q)) {
                $found = DB::connection('mysql_personal')->table('catalog_products')
                    ->where(function($qq) use ($q) {
                        $qq->where('product', 'like', '%' . $q . '%')
                           ->orWhere('sku', '=', $q)
                           ->orWhere('brand', 'like', '%' . $q . '%');
                    })
                    ->select('id')
                    ->first();

                if ($found) $catalogProductId = $found->id;
            }
        }

        $indicator = $catalogProductId ? 'we_have_or_had' : 'never_had';

        $wish = WishItem::create([
            'product_text' => $data['product_text'],
            'category' => $data['category'] ?? null,
            'catalog_product_id' => $catalogProductId,
            'indicator' => $indicator,
            'status' => 'pending',
            'user_id' => $data['user_id'] ?? null,
        ]);

        return response()->json($wish, 201);
    }

    // PATCH /api/v1/wish-items/{id}
    public function update(Request $request, $id)
    {
        $wish = WishItem::findOrFail($id);

        $data = $request->validate([
            'indicator' => ['nullable', Rule::in(['never_had','we_have_or_had'])],
            'status' => ['nullable', Rule::in(['pending','processed'])],
            'catalog_product_id' => 'nullable|integer|exists:mysql_personal.catalog_products,id',
        ]);

        if (array_key_exists('catalog_product_id', $data)) {
            $wish->catalog_product_id = $data['catalog_product_id'];
            // si asignas catalog => set indicator we_have_or_had
            if ($data['catalog_product_id']) $wish->indicator = 'we_have_or_had';
        }

        if (isset($data['indicator'])) $wish->indicator = $data['indicator'];
        if (isset($data['status'])) $wish->status = $data['status'];

        $wish->save();

        return response()->json($wish);
    }
     public function select(Request $request)
    {
        $data = $request->only(['wish_item_id', 'catalog_product_id', 'product_text', 'category', 'user_id', 'meta']);

        // basic validation
        $v = Validator::make($data, [
            'wish_item_id' => 'nullable|integer|exists:mysql_personal.wish_items,id',
            'catalog_product_id' => 'nullable|integer|exists:mysql_personal.catalog_products,id',
            'product_text' => 'nullable|string|max:1024',
            'category' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer'
        ]);

        if ($v->fails()) {
            return response()->json(['message' => 'Request inválido', 'errors' => $v->errors()], 422);
        }

        DB::connection('mysql_personal')->beginTransaction();
        try {
            $wish = null;

            // 1) Si viene wish_item_id -> incrementarlo
            if (!empty($data['wish_item_id'])) {
                $wish = WishItem::find($data['wish_item_id']);
                if (!$wish) throw new \Exception('Wish not found');
                $wish->count = ($wish->count ?? 0) + 1;
                $wish->save();

            } elseif (!empty($data['catalog_product_id'])) {
                // 2) Si viene catalog_product_id -> buscar wish existente ligado a ese catalog_product_id
                $wish = WishItem::where('catalog_product_id', $data['catalog_product_id'])->first();

                if ($wish) {
                    $wish->increment('count');
                    $wish->refresh();

                } else {
                    // crear nuevo wish vinculado al product (indicator = we_have_or_had)
                    $product = CatalogProduct::on('mysql_personal')->find($data['catalog_product_id']);
                    $wish = WishItem::create([
                        'product_text' => $data['product_text'] ?? ($product->product ?? null) ?? 'Sin descripción',
                        'category' => $data['category'] ?? ($product->category ?? null),
                        'catalog_product_id' => $data['catalog_product_id'],
                        'indicator' => 'we_have_or_had',
                        'status' => 'pending',
                        'user_id' => $data['user_id'] ?? null,
                        'count' => 1,
                    ]);
                }

            } elseif (!empty($data['product_text'])) {
                // 3) Solo texto: buscar por product_text similar (case-insensitive) y aumentar si existe, si no crear never_had
                $text = trim($data['product_text']);
                // búsqueda simple: coincidencia exacta o like (ajusta según necesidad)
                $wish = WishItem::whereRaw('LOWER(product_text) = ?', [mb_strtolower($text)])->first();
                if (!$wish) {
                    $wish = WishItem::where('product_text', 'like', '%' . $text . '%')->first();
                }

                if ($wish) {
                    $wish->count = ($wish->count ?? 0) + 1;
                    $wish->save();
                } else {
                    $wish = WishItem::create([
                        'product_text' => $text,
                        'category' => $data['category'] ?? null,
                        'catalog_product_id' => null,
                        'indicator' => 'never_had',
                        'status' => 'pending',
                        'user_id' => $data['user_id'] ?? null,
                        'count' => 1,
                    ]);
                }
            } else {
                throw new \Exception('No hay datos suficientes para crear selección');
            }

            // Registrar selección individual (para reportes por fecha)
            $selection = WishItemSelection::create([
                'wish_item_id' => $wish->id ?? null,
                'catalog_product_id' => $data['catalog_product_id'] ?? ($wish->catalog_product_id ?? null),
                'user_id' => $data['user_id'] ?? auth()->id() ?? null,
                'meta' => $data['meta'] ?? null
            ]);

            DB::connection('mysql_personal')->commit();

            // devolver wish y selección
            return response()->json([
                'wish' => $wish,
                'selection' => $selection
            ]);
        } catch (\Throwable $e) {
            DB::connection('mysql_personal')->rollBack();
            return response()->json(['message' => 'Error registrando selección', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/wish-items/stats
     * params: start (Y-m-d), end (Y-m-d)
     * Devuelve conteos por fecha (y opcional breakdown por wish_item_id)
     */
    public function stats(Request $request)
{
    $start = $request->query('start');
    $end = $request->query('end');
    $category = $request->query('category');

    $baseQuery = DB::connection('mysql_personal')
        ->table('wish_item_selections as s')
        ->leftJoin('wish_items as w', 's.wish_item_id', '=', 'w.id')
        ->leftJoin('catalog_products as c', 's.catalog_product_id', '=', 'c.id');

    if ($start) {
        $baseQuery->where('s.created_at', '>=', Carbon::parse($start)->startOfDay());
    }

    if ($end) {
        $baseQuery->where('s.created_at', '<=', Carbon::parse($end)->endOfDay());
    }

    if ($category) {
        $baseQuery->where(function($q) use ($category) {
            $q->where('w.category', $category)
              ->orWhere('c.category', $category);
        });
    }

    // === BY DAY ===
    $byDay = (clone $baseQuery)
        ->selectRaw('DATE(s.created_at) as day, COUNT(*) as total')
        ->groupBy(DB::raw('DATE(s.created_at)'))
        ->orderBy('day', 'asc')
        ->get();

    // === BY WISH ===
    $byWish = (clone $baseQuery)
        ->selectRaw('s.wish_item_id, COUNT(*) as total')
        ->groupBy('s.wish_item_id')
        ->orderByDesc('total')
        ->limit(50)
        ->get();

    return response()->json([
        'by_day' => $byDay,
        'by_wish' => $byWish
    ]);
}

    
    
public function sellers()
{
    $users = DB::table('users')
        ->whereIn('role', ['seller', 'cashier'])
        ->select('id', 'name', 'role')
        ->orderBy('name')
        ->get();

    return response()->json($users);
}



}
