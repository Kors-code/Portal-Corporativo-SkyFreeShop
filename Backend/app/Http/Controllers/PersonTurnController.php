<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comisiones\PersonTurn;

class PersonTurnController extends Controller
{
    public function index(Request $r) {
        $budgetId = $r->query('budget_id');
        $q = PersonTurn::with('user');
        if ($budgetId) $q->where('budget_id', $budgetId);
        return response()->json($q->get());
    }

    public function store(Request $request) {
        $data = $request->validate([
            'budget_id' => 'required|exists:budgets,id',
            'user_id' => 'required|exists:users,id',
            'turns' => 'required|integer|min:0'
        ]);
        $pt = PersonTurn::updateOrCreate(
            ['budget_id'=>$data['budget_id'],'user_id'=>$data['user_id']],
            ['turns'=>$data['turns']]
        );
        return response()->json($pt,201);
    }

    public function update(Request $request,$id) {
        $pt = PersonTurn::findOrFail($id);
        $data = $request->validate(['turns'=>'required|integer|min:0']);
        $pt->update($data);
        return response()->json($pt);
    }

    public function destroy($id) {
        PersonTurn::destroy($id);
        return response()->json(['message'=>'Asignación eliminada']);
    }
}
