<?php

namespace App\Http\Controllers;

use App\Models\AdjuntoPqrsd;
use App\Models\DescargaAdjuntoPqrsd;
use App\Models\Pqrsd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdjuntoPqrsdController extends Controller
{
    public function download(Request $request, Pqrsd $pqrsd, AdjuntoPqrsd $adjunto): StreamedResponse
    {
        $this->authorize('view', $pqrsd);

        if ((int) $adjunto->pqrsd_id !== (int) $pqrsd->id) {
            abort(404);
        }

        $ruta = $adjunto->ruta;
        if ($ruta === '' || str_contains($ruta, '..') || str_starts_with($ruta, '/') || str_contains($ruta, '\\..')) {
            abort(404);
        }

        $disk = Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'));

        if (! $disk->exists($ruta)) {
            abort(404);
        }

        DescargaAdjuntoPqrsd::create([
            'adjunto_pqrsd_id' => $adjunto->id,
            'pqrsd_id' => $pqrsd->id,
            'usuario_id' => Auth::id(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return $disk->download($ruta, $adjunto->nombre_original, [
            'Content-Type' => $adjunto->mime ?: 'application/octet-stream',
        ]);
    }
}
