<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'role',
        'role_id',
        'auth_correo',
        'seller_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // 🔥 RELACIÓN CON ROLE
    public function roleModel()
    {
        return $this->belongsTo(\App\Models\Role::class, 'role_id');
    }

    // 🔥 PERMISOS DIRECTOS
    public function permissions()
    {
        return $this->belongsToMany(
            \App\Models\Permission::class,
            'user_permissions'
        );
    }

    // 🔥 MÉTODO CLAVE
    public function hasPermission($permission)
    {
        // 1. Permisos directos
        if ($this->permissions()->where('name', $permission)->exists()) {
            return true;
        }

        // 2. Permisos por rol
        if ($this->roleModel) {
            return $this->roleModel->permissions()
                ->where('name', $permission)
                ->exists();
        }

        return false;
    }
}