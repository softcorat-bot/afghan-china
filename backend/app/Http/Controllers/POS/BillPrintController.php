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
     * Get bill HTML for print.js — a complete, self-contained HTML document.
     * (There is no Blade view for this: the receipt markup is generated in
     * PHP so the JSON and HTML endpoints print exactly the same bill.)
     */
    public function html(Sale $sale): Response
    {
        abort_unless($sale->company_id === Tenant::id(), 403);

        $title = htmlspecialchars('Bill '.($sale->invoice_no ?: '#'.$sale->id));
        $doc = '<!doctype html><html><head><meta charset="utf-8"><title>'.$title.'</title>'
            .'<style>@page{size:80mm auto;margin:0}body{margin:0}</style></head><body>'
            .$this->generateBillHtml($sale)
            .'</body></html>';

        return response($doc, 200, ['Content-Type' => 'text/html; charset=utf-8']);
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
            'receipt_settings' => $this->settings($sale),
            'bill_html' => $this->generateBillHtml($sale),
        ];
    }

    /**
     * Generate print-friendly HTML for the bill
     */
    private function generateBillHtml(Sale $sale): string
    {
        $company = $sale->company;
        $receiptSettings = $this->settings($sale);
        $companyName = $receiptSettings['header_name'] ?: ($company->name_en ?: ($company->name_fa ?? ''));
        $money = static fn ($v) => 'AFN '.number_format((float) $v, 0);

        $html = '<div class="receipt" style="font-family: monospace; max-width: 80mm; margin: 0; padding: 10mm;">';

        // Header
        $html .= '<div style="text-align: center; margin-bottom: 10mm;">';
        $logo = $receiptSettings['logo_url'] ?? $company->logo;
        if ($logo && ($receiptSettings['show_logo'] ?? true)) {
            $html .= '<img src="' . htmlspecialchars($logo) . '" style="max-width: 50mm; margin-bottom: 5mm;">';
        }
        $html .= '<h2 style="margin: 0; font-size: 16px;">' . htmlspecialchars($companyName) . '</h2>';
        foreach (['tagline', 'address', 'phone'] as $line) {
            $value = $receiptSettings[$line] ?: ($line === 'tagline' ? '' : ($company->{$line} ?? ''));
            if ($value) {
                $html .= '<p style="margin: 1mm 0; font-size: 11px;">' . htmlspecialchars($value) . '</p>';
            }
        }
        $html .= '</div>';

        // Bill details
        $html .= '<div style="margin-bottom: 5mm; font-size: 11px; border-bottom: 1px dashed #000; padding-bottom: 5mm;">';
        $html .= '<p style="margin: 1mm 0;"><strong>Bill ' . htmlspecialchars($sale->invoice_no ?: '#'.$sale->id) . '</strong></p>';
        $html .= '<p style="margin: 1mm 0;">Date: ' . ($sale->sold_at ?? $sale->created_at)?->format('Y-m-d H:i') . '</p>';
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
            $html .= '<td style="padding: 2mm 0;">' . htmlspecialchars($item->name ?: ($item->product->name ?? 'N/A')) . '</td>';
            $html .= '<td style="text-align: right; padding: 2mm 0;">' . (0 + $item->qty) . '</td>';
            $html .= '<td style="text-align: right; padding: 2mm 0;">' . $money($item->unit_price) . '</td>';
            $html .= '<td style="text-align: right; padding: 2mm 0;">' . $money($item->line_total) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        // Totals
        $html .= '<div style="margin-bottom: 5mm; font-size: 11px; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 3mm 0;">';
        $html .= '<div style="display: flex; justify-content: space-between; margin: 1mm 0;">';
        $html .= '<span>Subtotal:</span>';
        $html .= '<span>' . $money($sale->subtotal) . '</span>';
        $html .= '</div>';

        if ($sale->discount > 0) {
            $html .= '<div style="display: flex; justify-content: space-between; margin: 1mm 0;">';
            $html .= '<span>Discount:</span>';
            $html .= '<span>-' . $money($sale->discount) . '</span>';
            $html .= '</div>';
        }

        if ($sale->tax > 0) {
            $html .= '<div style="display: flex; justify-content: space-between; margin: 1mm 0;">';
            $html .= '<span>Tax:</span>';
            $html .= '<span>' . $money($sale->tax) . '</span>';
            $html .= '</div>';
        }

        $html .= '<div style="display: flex; justify-content: space-between; margin: 2mm 0; font-weight: bold; font-size: 12px;">';
        $html .= '<span>TOTAL:</span>';
        $html .= '<span>' . $money($sale->total) . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Payments
        if ($sale->payments->count() > 0) {
            $html .= '<div style="margin-bottom: 5mm; font-size: 11px;">';
            $html .= '<p style="margin: 1mm 0; font-weight: bold;">Payment:</p>';
            foreach ($sale->payments as $payment) {
                $html .= '<p style="margin: 1mm 0;">' . ucfirst($payment->method) . ': ' . $money($payment->amount) . '</p>';
            }
            if ($sale->change_due > 0) {
                $html .= '<p style="margin: 1mm 0; font-weight: bold;">Change Due: ' . $money($sale->change_due) . '</p>';
            }
            $html .= '</div>';
        }

        // Footer
        $footer = trim(($receiptSettings['footer'] ?? '').' '.($receiptSettings['note'] ?? ''));
        if ($footer !== '') {
            $html .= '<div style="text-align: center; margin-top: 5mm; font-size: 10px; border-top: 1px dashed #000; padding-top: 3mm;">';
            $html .= '<p style="margin: 1mm 0;">' . htmlspecialchars($footer) . '</p>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /** Saved receipt layout merged over the Receipt Designer defaults. */
    private function settings(Sale $sale): array
    {
        return array_merge(
            \App\Http\Controllers\Settings\ReceiptSettingsController::DEFAULTS,
            (array) ($sale->company->receipt_settings ?? [])
        );
    }
}
