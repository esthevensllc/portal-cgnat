<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Portal\PortalAuditService;

class PortalSessionController extends Controller
{
    public function destroy(Request $request, PortalAuditService $audit): RedirectResponse
    {
        $audit->record('auth.logout', 'success', (string) $request->session()->get('portal_auth.username'), $request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('portal.login');
    }
}
