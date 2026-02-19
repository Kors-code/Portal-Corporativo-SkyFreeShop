<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ShowPresupuesto extends Controller
{
    public function ShowPresupuesto(){
        return view('Presupuesto/presupuesto');
    }
}
