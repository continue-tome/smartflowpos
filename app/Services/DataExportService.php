<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Cancellation;
use App\Models\CakeOrder;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\CustomerTab;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataExportService
{
    private $xmlHeader = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
  </Style>
  <Style ss:ID="sHeader">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#4F81BD" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sTitle">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="14" ss:Bold="1" ss:Color="#1F4E78"/>
  </Style>
 </Styles>';

    public function exportToExcel(string $fromDate, string $toDate, int $restaurantId): string
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to   = Carbon::parse($toDate)->endOfDay();

        $fileName = 'Export_Omega_' . $from->format('Ymd') . '_au_' . $to->format('Ymd') . '.xls';
        $filePath = storage_path('app/' . $fileName);
        
        $handle = fopen($filePath, 'w');
        fwrite($handle, $this->xmlHeader);

        // 1. Sheet Rapport (Synthèse financière)
        $this->addWorksheet($handle, 'Rapport', ['Indicateur', 'Valeur'], function($cb) use ($restaurantId, $from, $to) {
            $stats = Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) as count, SUM(total) as total, SUM(discount_amount) as discount, SUM(vat_amount) as vat')
                ->first();

            $cb(['--- SYNTHESE DES VENTES ---', '']);
            $cb(['Période', $from->format('d/m/Y') . ' au ' . $to->format('d/m/Y')]);
            $cb(['Nombre de commandes validées', $stats->count ?? 0]);
            $cb(['Chiffre d\'Affaires TOTAL (TTC)', $stats->total ?? 0]);
            $cb(['Dont TVA (incluse dans le TTC)', $stats->vat ?? 0]);
            $cb(['Total Remises accordées', $stats->discount ?? 0]);
            
            $cb(['', '']);
            $cb(['--- REPARTITION DES PAIEMENTS ---', '']);
            $payments = Payment::whereBetween('created_at', [$from, $to])
                ->where(fn($q) => $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                                    ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId)))
                ->groupBy('method')
                ->selectRaw('method, SUM(amount) as total')
                ->get();

            foreach ($payments as $p) {
                $cb(['Total ' . strtoupper($p->method), $p->total]);
            }

            $cb(['', '']);
            $cb(['--- SYNTHESE CAISSE & DEPENSES ---', '']);
            $totalExpenses = Expense::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount');
            $cb(['Total Dépenses enregistrées', $totalExpenses]);

            $cb(['', '']);
            $cb(['--- SYNTHESE ARDOISES (CREDITS) ---', '']);
            $tabsStats = CustomerTab::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('SUM(total_amount) as total, SUM(paid_amount) as paid')
                ->first();
            $cb(['Total Montant Ardoises', $tabsStats->total ?? 0]);
            $cb(['Total Montant Recouvré', $tabsStats->paid ?? 0]);
            $cb(['Reste à recouvrer', ($tabsStats->total ?? 0) - ($tabsStats->paid ?? 0)]);

            $cb(['', '']);
            $cb(['--- ANNULATIONS ---', '']);
            $cancelled = Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'cancelled')
                ->count();
            $cb(['Nombre de commandes annulées', $cancelled]);
        });

        // 2. Sheet Analyse
        $this->addWorksheet($handle, 'Analyse', ['Rang', 'Article', 'Catégorie', 'Quantité Vendue', 'CA Généré'], function($cb) use ($restaurantId, $from, $to) {
            $topItems = OrderItem::with(['product.category'])
                ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereBetween('created_at', [$from, $to]))
                ->groupBy('product_id')
                ->selectRaw('product_id, SUM(quantity) as qty, SUM(subtotal) as total')
                ->orderByDesc('total')
                ->limit(30)
                ->get();

            $rank = 1;
            foreach ($topItems as $item) {
                $cb([
                    $rank++,
                    $item->product->name ?? 'Article inconnu',
                    $item->product->category->name ?? 'N/A',
                    $item->qty,
                    $item->total
                ]);
            }
        });

        // 3. Sheet Sessions Caisse
        $this->addWorksheet($handle, 'Caisse', ['ID', 'Caissier', 'Ouverte le', 'Fermée le', 'Ouverture', 'Attendu', 'Reel (Total)', 'Versé Banquier', 'Fond Restant', 'Écart', 'Statut'], function($cb) use ($restaurantId, $from, $to) {
            CashSession::with('user')
                ->where('restaurant_id', $restaurantId)
                ->whereBetween('opened_at', [$from, $to])
                ->chunk(100, function($sessions) use ($cb) {
                    foreach ($sessions as $s) {
                        $cb([
                            $s->id,
                            ($s->user->first_name ?? '') . ' ' . ($s->user->last_name ?? ''),
                            $s->opened_at->format('d/m/Y H:i'),
                            $s->closed_at ? $s->closed_at->format('d/m/Y H:i') : '-',
                            $s->opening_amount,
                            $s->expected_amount,
                            $s->closing_amount,
                            $s->amount_to_bank ?? 0,
                            $s->remaining_amount ?? 0,
                            $s->difference,
                            $s->status
                        ]);
                    }
                });
        });

        // 4. Sheet Dépenses
        $this->addWorksheet($handle, 'Depenses', ['Date', 'Libellé', 'Catégorie', 'Montant', 'Note', 'Par'], function($cb) use ($restaurantId, $from, $to) {
            Expense::with('user')
                ->where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($expenses) use ($cb) {
                    foreach ($expenses as $e) {
                        $cb([
                            $e->created_at->format('d/m/Y'),
                            $e->description,
                            $e->category,
                            $e->amount,
                            $e->notes,
                            $e->user->first_name ?? 'N/A'
                        ]);
                    }
                });
        });

        // 5. Sheet Orders
        $this->addWorksheet($handle, 'Commandes', ['ID', 'Numero', 'Type', 'Statut', 'Client', 'Couverts', 'Sous-total', 'Remise', 'TVA', 'TOTAL', 'Cree le', 'Paye le'], function($cb) use ($restaurantId, $from, $to) {
            Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($orders) use ($cb) {
                    foreach ($orders as $o) {
                        $cb([
                            $o->id, $o->order_number, $o->type, $o->status, $o->customer_name, $o->covers,
                            $o->subtotal, $o->discount_amount, $o->vat_amount, $o->total,
                            $o->created_at->format('d/m/Y H:i'),
                            $o->paid_at ? $o->paid_at->format('d/m/Y H:i') : ''
                        ]);
                    }
                });
        });

        // 6. Detailed Items
        $this->addWorksheet($handle, 'Détail Articles', ['ID', 'Commande #', 'Article', 'Quantite', 'Sous-total', 'Date'], function($cb) use ($restaurantId, $from, $to) {
            OrderItem::with(['order', 'product'])
                ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereBetween('created_at', [$from, $to]))
                ->chunk(100, function($items) use ($cb) {
                    foreach ($items as $item) {
                        $cb([
                            $item->id,
                            $item->order->order_number ?? 'N/A',
                            $item->product->name ?? 'N/A',
                            $item->quantity,
                            $item->subtotal,
                            $item->created_at->format('d/m/Y H:i')
                        ]);
                    }
                });
        });

        // 7. Gâteaux
        $this->addWorksheet($handle, 'Gateaux', ['Numero', 'Client', 'Date Recuperation', 'TOTAL', 'Encaisse', 'Statut'], function($cb) use ($restaurantId, $from, $to) {
            CakeOrder::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($cakes) use ($cb) {
                    foreach ($cakes as $cake) {
                        $cb([
                            $cake->order_number, $cake->customer_name,
                            ($cake->delivery_date ? $cake->delivery_date->format('d/m/Y') : '') . ' ' . ($cake->delivery_time ?? ''),
                            $cake->total, $cake->advance_paid, $cake->status
                        ]);
                    }
                });
        });

        // 8. Ardoises (Customer Tabs)
        $this->addWorksheet($handle, 'Ardoises', ['Client', 'Telephone', 'Total Ardoise', 'Deja Paye', 'Reste a Payer', 'Statut', 'Ouvert le', 'Paye le'], function($cb) use ($restaurantId, $from, $to) {
            CustomerTab::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->chunk(100, function($tabs) use ($cb) {
                    foreach ($tabs as $t) {
                        $cb([
                            $t->first_name . ' ' . $t->last_name,
                            $t->phone,
                            $t->total_amount,
                            $t->paid_amount,
                            $t->total_amount - $t->paid_amount,
                            $t->status,
                            $t->opened_at->format('d/m/Y H:i'),
                            $t->paid_at ? $t->paid_at->format('d/m/Y H:i') : '-'
                        ]);
                    }
                });
        });

        // 9. Logs
        $this->addWorksheet($handle, 'Logs Activité', ['ID', 'Date', 'Action', 'Sujet', 'Utilisateur'], function($cb) use ($from, $to) {
            DB::table('activity_logs')
                ->whereBetween('created_at', [$from, $to])
                ->orderByDesc('created_at')
                ->chunk(100, function($logs) use ($cb) {
                    foreach ($logs as $log) {
                        $cb([
                            $log->id,
                            Carbon::parse($log->created_at)->format('d/m/Y H:i'),
                            $log->description,
                            basename(str_replace('\\', '/', $log->subject_type ?? 'N/A')),
                            'UID: ' . ($log->causer_id ?? 'Système')
                        ]);
                    }
                });
        });

        fwrite($handle, '</Workbook>');
        fclose($handle);

        return $filePath;
    }

    private function addWorksheet($handle, $name, $headers, $queryCallback)
    {
        fwrite($handle, '<Worksheet ss:Name="' . $this->escapeXml($name) . '"><Table>');
        
        fwrite($handle, '<Row>');
        foreach ($headers as $h) {
            fwrite($handle, '<Cell ss:StyleID="sHeader"><Data ss:Type="String">' . $this->escapeXml($h) . '</Data></Cell>');
        }
        fwrite($handle, '</Row>');

        $queryCallback(function($row) use ($handle) {
            fwrite($handle, '<Row>');
            foreach ($row as $value) {
                $type = is_numeric($value) ? 'Number' : 'String';
                fwrite($handle, '<Cell><Data ss:Type="' . $type . '">' . $this->escapeXml((string)$value) . '</Data></Cell>');
            }
            fwrite($handle, '</Row>');
        });

        fwrite($handle, '</Table></Worksheet>');
    }

    private function escapeXml($text)
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
