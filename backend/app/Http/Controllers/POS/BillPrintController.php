<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * Bill Printing with print.js integration
 * Returns HTML/PDF for printing receipts
 */
class BillPrintController extends Controller
{
    /**
     * Get bill HTML for print.js
     */
    public function html(Sale $sale): Response
    {
        abort_unless($sale->company_id === Tenant::id(), 403);

        $company = $sale->company;
        $bill = $this->prepareBillData($sale);

        return response()->view('receipts.bill', $bill, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Get bill data as JSON (for print.js integration)
     */
    public function data(Sale $sale): JsonResponse
    {
        abort_unless($sale->company_id === Tenant::id(), 403);

        return response()->json($this->prepareBillData($sale));
    }

    /**
     * Prepare bill data for printing
     */
    private function prepareBillData(Sale $sale): array
    {
        return [
            'sale' => $sale,
            'items' => $sale->items()->with('product')->get(),
            'payments' => $sale->payments,
            'customer' => $sale->customer,
            'cashier' => $sale->cashier,
            'counter' => $sale->counter,
            'company' => $sale->company,
            'receipt_settings' => $sale->company->receipt_settings ?? [],
            'bill_html' => $this->generateBillHtml($sale),
        ];
    }

    /**
     * Generate print-friendly HTML for the bill
     */
    private function generateBillHtml(Sale $sale): string
    {
        $company = $sale->company;
        $receiptSettings = $company->receipt_settings ?? [];

        $html = '<div class="receipt" style="font-family: monospace; max-width: 80mm; margin: 0; padding: 10mm;">';

        // Header
        $html .= '<div style="text-align: center; margin-bottom: 10mm;">';
        if ($receiptSettings['logo_url'] ?? null) {
            $html .= '<img src="' . htmlspecialchars($receiptSettings['logo_url']) . '" style="max-width: 50mm; margin-bottom: 5mm;">';
        }
        $html .= '<h2 style="margin: 0; font-size: 16px;">' . htmlspecialchars($company->name) . '</h2>';
        if ($receiptSettings['header_text'] ?? null) {
            $html .= '<p style="margin: 2mm 0; font-size: 11px;">' . htmlspecialchars($receiptSettings['header_text']) . '</p>';
        }
        $html .= '</div>';

        // Bill details
        $html .= '<div style="margin-bottom: 5mm; font-size: 11px; border-bottom: 1px dashed #000; padding-bottom: 5mm;">';
        $html .= '<p style="margin: 1mm 0;"><strong>Bill #' . htmlspecialchars($sale->id) . '</strong></p>';
        $html .= '<p style="margin: 1mm 0;">Date: ' . $sale->sold_at->format('Y-m-d H:i') . '</p>';
        if ($sale->customer) {
            $html .= '<p style="margin: 1mm 0;">Customer: ' . htmlspecialchars($sale->customer->name) . '</p>';
        }
        if ($sale->counter) {
            $html .= '<p style="margin: 1mm 0;">Counter: ' . htmlspecialchars($sale->counter->name) . '</p>';
        }
        if ($sale->cashier) {
            $html .= '<p style="margin: 1mm 0;">Cashier: ' . htmlspecialchars($sale->cashier->name) . '</p>';
        }
        $html .= '</div>';

        // Items
        $html .= '<div style="margin-bottom: 5mm; font-size: 11px;">';
        $html .= '<table style="width: 100%; border-collapse: collapse;">';
        $html .= '<thead>';
        $html .= '<tr style="border-bottom: 1px solid #000;">';
        $html .= '<th style="text-align: left; padding: 2mm 0;">Item</th>';
        $html .= '<th style="text-align: right; padding: 2mm 0;">Qty</th>';
        $html .= '<th style="text-align: right; padding: 2mm 0;">Price</th>';
        $html .= '<th style="text-align: right; padding: 2mm 0;">Total</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($sale->items as $item) {
            $html .= '<tr>';
            $html .= '<td style="padding: 2mm 0;">' . htmlspecialchars($item->product->name ?? 'N/A') . '</td>';
            $html .= '<td style="text-align: right; padding: 2mm 0;">' . $item->quantity . '</td>';
            $html .= '<td style="text-align: right; padding: 2mm 0;">AFN ' . number_format($item->unit_price, 0) . '</td>';
            $html .= '<td style="text-align: right; padding: 2mm 0;">AFN ' . number_format($item->total, 0) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        // Totals
        $html .= '<div style="margin-bottom: 5mm; font-size: 11px; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 3mm 0;">';
        $html .= '<div style="display: flex; justify-content: space-between; margin: 1mm 0;">';
        $html .= '<span>Subtotal:</span>';
        $html .= '<span>AFN ' . number_format($sale->subtotal, 0) . '</span>';
        $html .= '</div>';

        if ($sale->discount > 0) {
            $html .= '<div style="display: flex; justify-content: space-between; margin: 1mm 0;">';
            $html .= '<span>Discount:</span>';
            $html .= '<span>-AFN ' . number_format($sale->discount, 0) . '</span>';
            $html .= '</div>';
        }

        if ($sale->tax > 0) {
            $html .= '<div style="display: flex; justify-content: space-between; margin: 1mm 0;">';
            $html .= '<span>Tax:</span>';
            $html .= '<span>AFN ' . number_format($sale->tax, 0) . '</span>';
            $html .= '</div>';
        }

        $html .= '<div style="display: flex; justify-content: space-between; margin: 2mm 0; font-weight: bold; font-size: 12px;">';
        $html .= '<span>TOTAL:</span>';
        $html .= '<span>AFN ' . number_format($sale->total, 0) . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Payments
        if ($sale->payments->count() > 0) {
            $html .= '<div style="margin-bottom: 5mm; font-size: 11px;">';
            $html .= '<p style="margin: 1mm 0; font-weight: bold;">Payment:</p>';
            foreach ($sale->payments as $payment) {
                $html .= '<p style="margin: 1mm 0;">' . ucfirst($payment->method) . ': AFN ' . number_format($payment->amount, 0) . '</p>';
            }
            if ($sale->change_due > 0) {
                $html .= '<p style="margin: 1mm 0; font-weight: bold;">Change Due: AFN ' . number_format($sale->change_due, 0) . '</p>';
            }
            $html .= '</div>';
        }

        // Footer
        if ($receiptSettings['footer_text'] ?? null) {
            $html .= '<div style="text-align: center; margin-top: 5mm; font-size: 10px; border-top: 1px dashed #000; padding-top: 3mm;">';
            $html .= '<p style="margin: 1mm 0;">' . htmlspecialchars($receiptSettings['footer_text']) . '</p>';
            $html .= '</div>';
        }

        $html .= '<div style="text-align: center; margin-top: 5mm; font-size: 10px;">';
        $html .= '<p style="margin: 1mm 0;">Thank you for your purchase!</p>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
