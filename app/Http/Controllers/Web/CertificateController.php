<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\TrustedCertificate;
use App\Support\CertificateService;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CertificateController
{
    public function __construct(private CertificateService $certs) {}

    public function index(Request $request): View
    {
        $me = app(PermissionService::class)->employee($request->user());
        $items = TrustedCertificate::where('company_id', $me?->company_id)->orWhereNull('company_id')->orderByDesc('id')->get();

        return view('certificates.index', ['items' => $items]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'in:company,state'],
            'name' => ['required', 'string', 'max:255'],
            'pem' => ['required', 'string'],
        ]);
        $meta = $this->certs->parse($request->input('pem'));
        if ($meta === null) {
            return back()->withErrors(['pem' => __('Некорректный сертификат PEM.')]);
        }
        $me = app(PermissionService::class)->employee($request->user());
        TrustedCertificate::create([
            'company_id' => $me?->company_id,
            'type' => $request->input('type'),
            'name' => $request->input('name'),
            'subject' => $meta['subject'],
            'issuer' => $meta['issuer'],
            'serial' => $meta['serial'],
            'fingerprint' => $meta['fingerprint'],
            'pem' => $request->input('pem'),
            'is_active' => true,
        ]);

        return redirect()->route('certificates.index')->with('status', __('Сертификат добавлен'));
    }

    public function destroy(TrustedCertificate $certificate): RedirectResponse
    {
        $certificate->delete();

        return redirect()->route('certificates.index')->with('status', __('Сертификат удалён'));
    }
}
