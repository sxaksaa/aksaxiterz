<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $period = in_array($request->input('period'), ['today', '7', '30', 'all'], true)
            ? $request->input('period')
            : 'all';

        $logs = AdminActivityLog::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('admin_name', 'like', '%'.$search.'%')
                        ->orWhere('admin_email', 'like', '%'.$search.'%')
                        ->orWhere('subject_label', 'like', '%'.$search.'%')
                        ->orWhere('details', 'like', '%'.$search.'%')
                        ->orWhere('action', 'like', '%'.$search.'%');
                });
            })
            ->when(array_key_exists((string) $request->input('section'), AdminActivityLog::sectionOptions()), function ($query) use ($request) {
                $query->where('section', $request->input('section'));
            })
            ->when($period === 'today', fn ($query) => $query->where('created_at', '>=', now()->startOfDay()))
            ->when($period === '7', fn ($query) => $query->where('created_at', '>=', now()->subDays(7)))
            ->when($period === '30', fn ($query) => $query->where('created_at', '>=', now()->subDays(30)))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => AdminActivityLog::count(),
            'today' => AdminActivityLog::where('created_at', '>=', now()->startOfDay())->count(),
            'seven_days' => AdminActivityLog::where('created_at', '>=', now()->subDays(7))->count(),
            'admins' => AdminActivityLog::distinct('admin_email')->count('admin_email'),
        ];

        $sectionOptions = AdminActivityLog::sectionOptions();

        return view('admin.activity-logs.index', compact('logs', 'stats', 'sectionOptions', 'period'));
    }
}
