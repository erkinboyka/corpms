<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Company;
use App\Support\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController
{
    public function index(Request $request): View|RedirectResponse
    {
        $me = app(PermissionService::class)->employee($request->user());
        if ($me === null) {
            return redirect()->route('dashboard');
        }

        $company = Company::find($me->company_id);
        if ($company && $company->onboarding_completed_at !== null) {
            return redirect()->route('dashboard');
        }

        return view('onboarding.index', ['company' => $company]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = app(PermissionService::class)->employee($request->user());
        if ($me === null) {
            return redirect()->route('dashboard');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'language' => ['required', 'in:ru,en,tg,uz'],
        ]);

        $company = Company::find($me->company_id);
        if (! $company) {
            return redirect()->route('dashboard');
        }

        $company->fill([
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? $company->legal_name,
            'address' => $data['address'] ?? $company->address,
            'phone' => $data['phone'] ?? $company->phone,
            'email' => $data['email'] ?? $company->email,
            'onboarding_step' => 1,
            'onboarding_completed_at' => now(),
        ])->save();

        $request->user()->forceFill(['locale' => $data['language']])->save();
        $request->session()->put('locale', $data['language']);

        return redirect()->route('dashboard')->with('status', __('Онбординг завершён'));
    }
}