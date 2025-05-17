<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TipsaService;

class TipsaController extends Controller
{
    public function index()
    {
        $tipsa = new TipsaService('000000', '123456', 'miClave');
        $envios = $tipsa->getEnviosByDate(now()->format('Y/m/d'));
        return response()->json($envios);
    }
}
