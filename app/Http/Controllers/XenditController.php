<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Configuration as XenditConfiguration;
use Xendit\Invoice\InvoiceCallback as XenditWebhook;

class XenditController extends Controller
{
    //
    public function __construct()
    {
        $xendit_secret_key = env('XENDIT_SECRET_KEY');
        XenditConfiguration::setXenditKey($xendit_secret_key);
    }

    public function createInvoice(Request $request)
    {

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


    public function notification(Request $request)
    {
        $payment = Invoice::where('external_id', $request->external_id)->firstOrFail();

        $invoice_callback = new XenditWebhook([
            'external_id' => $payment->external_id,
            'status' => $request->input('status'),
            'updated_at' => $request->input('updated'),
        ]);

        $payment->status = $invoice_callback->getStatus();
        $payment->updated_at = Carbon::now()->toIso8601String();
        $payment->save();


        
        if($payment->status == 'PAID') {
            // Do something when the invoice is paid
            return response()->json([
                'status' => 'success',
                'message' => 'Invoice is paid',
            ], 200);
        }else{
            // Do something when the invoice is not paid
            return response()->json([
                'status' => 'error',
                'message' => 'Invoice is not paid',
            ], 200);
        }   

        return $invoice_callback->getId();
    }
}
