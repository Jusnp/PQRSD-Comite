<?php

namespace App\Http\Controllers;

use App\Services\Ops\OpsHealthService;
use Illuminate\View\View;

class OpsHealthController extends Controller
{
    public function __invoke(OpsHealthService $health): View
    {
        abort_unless(auth()->user()?->can('correos.manage') || auth()->user()?->can('auditoria.view'), 403);

        return view('ops.health', [
            'snap' => $health->snapshot(),
        ]);
    }
}
