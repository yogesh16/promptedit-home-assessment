<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest('id')
            ->paginate(10);

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
        ]);
    }
}
