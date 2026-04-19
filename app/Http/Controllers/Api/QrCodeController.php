<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends Controller
{
    public function generate(Request $request, int $tableId) {
        $table = Table::whereHas('floor', fn($q) => $q->where('restaurant_id', $request->user()->restaurant_id))->findOrFail($tableId);
        $restaurant = $request->user()->restaurant;
        $menuUrl = config('app.frontend_url') . "/menu/{$restaurant->slug}?table={$tableId}";
        $qr = QrCode::format('png')->size(300)->margin(2)->errorCorrection('H')->generate($menuUrl);
        return response($qr, 200, ['Content-Type' => 'image/png', 'Content-Disposition' => "inline; filename=table-{$table->number}-qr.png"]);
    }

    public function url(Request $request, int $tableId) {
        $table = Table::whereHas('floor', fn($q) => $q->where('restaurant_id', $request->user()->restaurant_id))->findOrFail($tableId);
        $restaurant = $request->user()->restaurant;
        $menuUrl = config('app.frontend_url') . "/menu/{$restaurant->slug}?table={$tableId}";
        return response()->json(['table_number' => $table->number, 'menu_url' => $menuUrl]);
    }

    public function allForFloor(Request $request, int $floorId) {
        $floor = \App\Models\Floor::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($floorId);
        $restaurant = $request->user()->restaurant;
        $tables = $floor->tables()->where('active', true)->get();
        $zipPath = storage_path("app/temp/qr-floor-{$floorId}.zip");
        if (!is_dir(storage_path('app/temp'))) { mkdir(storage_path('app/temp'), 0755, true); }
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($tables as $table) {
            $menuUrl = config('app.frontend_url') . "/menu/{$restaurant->slug}?table={$table->id}";
            $qr = QrCode::format('png')->size(300)->generate($menuUrl);
            $zip->addFromString("table-{$table->number}.png", $qr);
        }
        $zip->close();
        return response()->download($zipPath, "qr-codes-salle-{$floor->name}.zip")->deleteFileAfterSend();
    }
}
