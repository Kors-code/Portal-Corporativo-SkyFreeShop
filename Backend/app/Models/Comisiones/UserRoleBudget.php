<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleBudget extends Model
{
    protected $table = 'user_role_budgets';
        protected $connection = 'budget';


    protected $fillable = [
        'user_id',
        'role_id',
        'budget_id',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    /* =========================
     * Relaciones
     * ========================= */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
    protected function roleNameAtDate(User $user, ?string $date, ?int $budgetId = null): ?string
{
    if (!$date) return null;

    $query = UserRoleBudget::with('role')
        ->where('user_id', $user->id)
        ->activeAt($date);

    if ($budgetId) {
        $query->where('budget_id', $budgetId);
    }

    $record = $query
        ->orderBy('start_date', 'desc')
        ->first();

    return $record?->role?->name;
}

    /* =========================
     * Scopes útiles
     * ========================= */

    /**
     * Rol activo en una fecha dada
     */
    public function scopeActiveAt($query, string $date)
    {
        return $query
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $date);
            });
    }

    /**
     * Rol activo actualmente
     */
    public function scopeActive($query)
    {
        return $this->scopeActiveAt($query, now()->toDateString());
    }
}
