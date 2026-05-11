<?php

namespace App\Http\Controllers;

use App\Support\Database;
use App\Support\SyncFlags;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $counts = [
            'products' => 0,
            'orders' => 0,
            'sync_runs' => 0,
        ];
        try {
            $counts['products'] = (int) Database::fetchValue('SELECT COUNT(*) FROM products');
            $counts['orders'] = (int) Database::fetchValue('SELECT COUNT(*) FROM orders');
            $counts['sync_runs'] = (int) Database::fetchValue('SELECT COUNT(*) FROM sync_runs');
        } catch (\Throwable) {
            // Schema missing or DB offline — show zeros
        }

        return view('syncbridge.dashboard', [
            'counts' => $counts,
            'psBaseUrl' => (string) ($_ENV['PS_BASE_URL'] ?? ''),
            'ayBaseUrl' => (string) ($_ENV['AY_BASE_URL'] ?? ''),
            'dryRun' => SyncFlags::dryRun(),
            'testMode' => SyncFlags::testMode(),
            'appUrl' => rtrim((string) config('app.url', ''), '/'),
        ]);
    }
}
