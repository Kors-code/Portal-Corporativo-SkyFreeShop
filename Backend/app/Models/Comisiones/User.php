<?php
namespace App\Models\Comisiones;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model; 

class User extends Model
{

protected $connection = 'budget';
    protected $fillable = [
        'name',
        'email',
        'codigo_vendedor'
    ];

    /**
     * Ventas donde el usuario actúa como vendedor
     * FK real: sales.seller_id
     */
    public function salesAsSeller(): HasMany
    {
        return $this->hasMany(Sale::class, 'seller_id', 'id');
    }

    /**
     * Roles históricos del usuario
     * FK real: user_roles.user_id
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    /**
     * Alias explícito (si prefieres usar roles())
     */
    public function roles(): HasMany
    {
        return $this->userRoles();
    }

    /**
     * Rol vigente en una fecha dada
     */
    public function roleAtDate(?string $date = null)
    {
        $date = $date ?? now()->toDateString();

        return $this->userRoles()
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $date);
            })
            ->with('role')
            ->orderBy('start_date', 'desc')
            ->first();
    }
    public function roleBudgets()
{
    return $this->hasMany(UserRoleBudget::class, 'user_id');
}


    /**
     * ❌ NO hay relación salesAsCashier
     *
     * sales.cashier es TEXTO, no FK.
     * Cualquier análisis de cajeros debe hacerse:
     * - por import
     * - por reportes
     * - o por lógica explícita
     */
}
