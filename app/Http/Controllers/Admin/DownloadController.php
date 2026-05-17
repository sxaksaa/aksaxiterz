<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DownloadItem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DownloadController extends Controller
{
    public function index(Request $request)
    {
        $downloads = DownloadItem::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $editDownload = null;

        if ($request->filled('edit')) {
            $editDownload = DownloadItem::find($request->integer('edit'));
        }

        $stats = [
            'total' => DownloadItem::count(),
            'links' => DownloadItem::all()->sum(fn ($download) => count($download->links ?: [])),
        ];

        return view('admin.downloads.index', compact('downloads', 'editDownload', 'stats'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateDownload($request);

        DownloadItem::create($validated);

        return redirect()
            ->route('admin.downloads.index')
            ->with('info', 'Download item added.');
    }

    public function update(Request $request, DownloadItem $download)
    {
        $validated = $this->validateDownload($request);

        $download->update($validated);

        return redirect()
            ->route('admin.downloads.index')
            ->with('info', 'Download item updated.');
    }

    public function destroy(DownloadItem $download)
    {
        $download->delete();

        return redirect()
            ->route('admin.downloads.index')
            ->with('info', 'Download item deleted.');
    }

    private function validateDownload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'links_text' => ['nullable', 'string', 'max:20000'],
        ]);

        return [
            'name' => $validated['name'],
            'links' => $this->parseLinks($validated['links_text'] ?? ''),
        ];
    }

    private function parseLinks(string $value): array
    {
        $links = [];
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$label, $url] = str_contains($line, '|')
                ? array_map('trim', explode('|', $line, 2))
                : ['Download', $line];

            if (! $this->validUrl($url)) {
                throw ValidationException::withMessages([
                    'links_text' => 'Invalid download URL on line '.($lineNumber + 1).'. Use a full https:// link.',
                ]);
            }

            $links[] = [
                'label' => $label !== '' ? $label : 'Download',
                'url' => $url,
            ];
        }

        return $links;
    }

    private function validUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
