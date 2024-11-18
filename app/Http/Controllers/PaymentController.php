<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Invoice;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Configuration as XenditConfiguration;
use Xendit\Invoice\InvoiceCallback as XenditWebhook;

class PaymentController extends Controller
{
    public function __construct()
    {
        $xendit_secret_key = env('XENDIT_SECRET_KEY');
        XenditConfiguration::setXenditKey($xendit_secret_key);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $invoices = Invoice::all();
        return response()->json($invoices);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $apiInstance = new InvoiceApi();

        try {
            $createInvoiceRequest = new CreateInvoiceRequest([
                'external_id' => uniqid(),
                'amount' => $request->input('amount'),
                'payer_email' => $request->input('payer_email'),
                'description' => $request->input('description'),
                'status' => 'PENDING',
                'currency' => 'IDR',
                'invoice_duration' => 86400,
                'reminder_time' => 1,
                'success_redirect_url' => env('SUCCESS_URL'),
                'failure_redirect_url' => env('FAILURE_URL'),
            ]);

            $invoice = $apiInstance->createInvoice($createInvoiceRequest);

            $payment = new Invoice();
            $payment->external_id = $invoice['external_id'];
            $payment->amount = $invoice['amount'];
            $payment->payer_email = $invoice['payer_email'];
            $payment->description = $invoice['description'];
            $payment->invoice_url = $invoice['invoice_url'];
            $payment->status = $invoice['status'];
            $payment->created_at = Carbon::now()->toIso8601String();
            $payment->save();

            return response()->json([
                'status' => 'pending',
                'invoice_url' => $invoice['invoice_url'],
                'external_id' => $invoice['external_id'],
                'updated_at' => $invoice['updated'],
                'created_at' => $invoice['created'],
            ], 201);
        } catch (XenditSdkException $e) {
            Log::error('Xendit API error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function notification(Request $request)
    {
        // Ambil token dari header (contoh jika menggunakan 'X-Callback-Token')
        $callbackToken = $request->header('X-Callback-Token');

        // Verifikasi token
        if ($callbackToken !== env('XENDIT_WEBHOOK_SECRET')) {
            return response()->json(['status' => 'error', 'message' => 'Akses Anda ditolak, karena alasan security'], 401);
        }

        // Lanjutkan proses jika verifikasi berhasil
        $payment = Invoice::where('external_id', $request->external_id)->firstOrFail();

        $invoice_callback = new XenditWebhook([
            'external_id' => $payment->external_id,
            'status' => $request->input('status'),
            'updated_at' => $request->input('updated'),
        ]);

        $payment->status = $invoice_callback->getStatus();
        $payment->updated_at = Carbon::now()->toIso8601String();
        $payment->save();

        if ($payment->status == 'PAID') {
            // Tindakan ketika invoice dibayar
            return response()->json(['status' => 'success', 'message' => 'Invoice is paid'], 200);
        } else {
            // Tindakan ketika invoice tidak dibayar
            return response()->json(['status' => 'error', 'message' => 'Invoice is not paid'], 200);
        }

        return $invoice_callback->getId();
    }
}
