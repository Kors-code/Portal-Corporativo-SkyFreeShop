<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class PersonTurn extends Model {
        protected $connection = 'budget';

    protected $fillable = ['budget_id','user_id','turns'];
    public function user() { return $this->belongsTo(User::class); }
    public function budget() { return $this->belongsTo(Budget::class); }
}
