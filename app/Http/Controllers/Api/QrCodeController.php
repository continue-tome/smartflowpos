<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends Controller
{
    public function generate(Request $request, int $tableId) {
        $table = Table::with('floor.restaurant')->findOrFail($tableId);
        $restaurant = $table->floor->restaurant;
        $menuUrl = config('app.frontend_url') . "?table={$tableId}";
        $qr = QrCode::size(300)->margin(2)->errorCorrection('H')->generate($menuUrl);
        return response($qr, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function allForFloor(Request $request, int $floorId) {
        // ... (existing zip logic)
    }

    public function downloadPdf(Request $request) {
        $tables = Table::get();
        if ($tables->isEmpty()) return response()->json(['message' => 'No tables found'], 404);

        $html = '<html><head><style>
            body { font-family: sans-serif; margin: 0; padding: 10px; }
            .grid { width: 100%; border-collapse: collapse; }
            .card { 
                width: 17.5%; 
                display: inline-block; 
                border: 0.3px solid #eee; 
                margin: 0.4%; 
                padding: 8px 2px; 
                text-align: center;
                border-radius: 5px;
                page-break-inside: avoid;
                vertical-align: top;
            }
            .qr { width: 80px; height: 80px; margin: 2px auto; }
            .qr img { width: 100%; height: auto; }
            .table-num { font-size: 14px; font-weight: bold; margin: 2px 0; line-height: 1; }
            .url { font-size: 5px; color: #888; word-wrap: break-word; line-height: 1.1; display: block; height: 12px; overflow: hidden; }
            .header-text { font-size: 7px; font-weight: bold; color: #ccc; text-transform: uppercase; }
        </style></head><body>';

        $html .= '<div style="text-align:center; font-size: 10px; font-weight: bold; margin-bottom: 10px;">QR - SmartFlow POS</div>';
        $html .= '<div class="grid">';
        
        foreach ($tables as $index => $table) {
            $menuUrl = config('app.frontend_url') . "?table={$table->id}";
            $qrSvg = QrCode::size(120)->margin(0)->generate($menuUrl);
            $base64Qr = base64_encode($qrSvg);
            
            $html .= '<div class="card"><div class="header-text">SCANNER</div><div class="qr"><img src="data:image/svg+xml;base64,' . $base64Qr . '"></div><div class="table-num">N.' . $table->number . '</div><div class="url">' . $menuUrl . '</div></div>';
        }

        $html .= '</div></body></html>';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        return $pdf->download('QR_Codes_SmartFlow.pdf');
    }
}
