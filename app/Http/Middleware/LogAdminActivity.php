<?php

namespace App\Http\Middleware;

use App\Models\AdminActivityLog;
use App\Models\LicenseStock;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldRecord($request, $response)) {
            return $response;
        }

        try {
            $this->record($request);
        } catch (Throwable $error) {
            Log::warning('ADMIN ACTIVITY LOG ERROR: '.$error->getMessage(), [
                'action' => $request->route()?->getName(),
                'admin_id' => $request->user()?->id,
            ]);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        $action = (string) $request->route()?->getName();
        $errors = $request->hasSession() ? $request->session()->get('errors') : null;

        return $request->user()?->isAdmin()
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && array_key_exists($action, AdminActivityLog::actionLabels())
            && $response->getStatusCode() >= 200
            && $response->getStatusCode() < 400
            && ! ($errors && $errors->any())
            && Schema::hasTable('admin_activity_logs');
    }

    private function record(Request $request): void
    {
        $admin = $request->user();
        $action = (string) $request->route()?->getName();
        $subject = $this->subject($request, $action);

        AdminActivityLog::create([
            'user_id' => $admin->id,
            'admin_name' => Str::limit(trim((string) $admin->name) ?: 'Admin', 120, ''),
            'admin_email' => Str::limit(strtolower((string) $admin->email), 190, ''),
            'section' => AdminActivityLog::sectionForAction($action),
            'action' => $action,
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'subject_label' => $subject['label'],
            'details' => $this->safeDetails($request, $action),
            'method' => $request->method(),
            'ip_address' => Str::limit((string) $request->ip(), 45, ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'created_at' => now(),
        ]);
    }

    private function subject(Request $request, string $action): array
    {
        $storeSubject = $this->storeSubject($request, $action);

        if ($storeSubject) {
            return $storeSubject;
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if (! $parameter instanceof Model) {
                continue;
            }

            return [
                'type' => class_basename($parameter),
                'id' => (string) $parameter->getKey(),
                'label' => $this->modelLabel($parameter),
            ];
        }

        return ['type' => null, 'id' => null, 'label' => null];
    }

    private function storeSubject(Request $request, string $action): ?array
    {
        return match ($action) {
            'admin.products.store' => $this->requestSubject('Product', $request->input('name')),
            'admin.categories.store' => $this->requestSubject('Category', $request->input('name')),
            'admin.downloads.store' => $this->requestSubject('DownloadItem', $request->input('name')),
            'admin.vouchers.store' => $this->requestSubject('Voucher', $request->input('code')),
            'admin.license-stocks.store' => $this->requestSubject('LicenseStock', 'Bulk stock import'),
            'admin.products.packages.store' => $this->requestSubject('Package', $request->input('package_name')),
            default => null,
        };
    }

    private function requestSubject(string $type, mixed $label): array
    {
        $label = trim((string) $label);

        return [
            'type' => $type,
            'id' => null,
            'label' => $label !== '' ? Str::limit($label, 190, '') : null,
        ];
    }

    private function modelLabel(Model $model): string
    {
        if ($model instanceof LicenseStock) {
            return 'License stock #'.$model->getKey();
        }

        foreach (['order_id', 'code', 'name', 'slug'] as $attribute) {
            $value = trim((string) $model->getAttribute($attribute));

            if ($value !== '') {
                return Str::limit($value, 190, '');
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    private function safeDetails(Request $request, string $action): ?string
    {
        $details = match (true) {
            in_array($action, ['admin.products.store', 'admin.products.update'], true) => [
                $request->filled('status') ? 'Status: '.$request->string('status')->toString() : null,
                $request->has('is_visible')
                    ? 'Visibility: '.($request->boolean('is_visible') ? 'public' : 'hidden')
                    : null,
            ],
            in_array($action, ['admin.products.packages.store', 'admin.packages.update'], true) => [
                trim((string) $request->input('package_name')),
                'IDR '.number_format(max(0, $request->integer('package_price'))),
            ],
            in_array($action, ['admin.categories.store', 'admin.categories.update'], true) => [
                trim((string) $request->input('slug')),
            ],
            in_array($action, ['admin.downloads.store', 'admin.downloads.update'], true) => [
                trim((string) $request->input('name')),
            ],
            in_array($action, ['admin.vouchers.store', 'admin.vouchers.update'], true) => [
                strtoupper(trim((string) $request->input('code'))),
            ],
            $action === 'admin.license-stocks.store' => [
                $this->licenseKeyCount((string) $request->input('license_keys')).' key(s)',
                'Product #'.$request->integer('product_id'),
                'Package #'.$request->integer('package_id'),
            ],
            $action === 'admin.license-stocks.update' => [
                'Product #'.$request->integer('product_id'),
                'Package #'.$request->integer('package_id'),
            ],
            default => [],
        };

        $value = collect($details)
            ->map(fn ($detail) => trim((string) $detail))
            ->filter(fn ($detail) => $detail !== '' && ! str_ends_with($detail, '#0'))
            ->implode(' · ');

        return $value !== '' ? Str::limit($value, 255, '') : null;
    }

    private function licenseKeyCount(string $value): int
    {
        return collect(preg_split('/[\r\n,;]+/', $value))
            ->map(fn ($key) => trim((string) $key))
            ->filter()
            ->unique()
            ->count();
    }
}
