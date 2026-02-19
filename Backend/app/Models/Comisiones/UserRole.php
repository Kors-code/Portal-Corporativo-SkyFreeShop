<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class UserRole extends Model {
        protected $connection = 'budget';

    protected $fillable = ['user_id','role_id','start_date','end_date'];
    public function user() { return $this->belongsTo(User::class); }
    public function role() { return $this->belongsTo(Role::class); }
}
