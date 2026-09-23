<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Portal\PortalAuditService;
use App\Services\Portal\PortalSessionRegistry;

class PortalSessionController extends Controller
{
    public function destroy(Request $request, PortalAuditService $audit, PortalSessionRegistry $sessions): RedirectResponse
    {
        $sessions->release($request, (string) $request->session()->get('portal_auth.username'));
        $audit->record('auth.logout', 'success', (string) $request->session()->get('portal_auth.username'), $request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('portal.login');
    }
}
