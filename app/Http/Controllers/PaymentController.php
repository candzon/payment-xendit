<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Invoice;
use App\Models\Product;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Configuration as XenditConfiguration;
use Xendit\Invoice\InvoiceCallback as XenditWebhook;

class PaymentController extends Controller
{
    /**
     * Menginisialisasi konfigurasi Xendit Payment Gateway.
     * 
     * Constructor ini akan mengatur secret key Xendit yang diperlukan untuk integrasi pembayaran.
     * Secret key diambil dari environment variable 'XENDIT_SECRET_KEY'.
     * 
     * @return void
     * @throws \Exception jika XENDIT_SECRET_KEY tidak ditemukan di environment variables
     */
    public function __construct()
    {
        $xendit_secret_key = env('XENDIT_SECRET_KEY');

        if (!$xendit_secret_key) {
            throw new \Exception('Xendit secret key not found in environment variables');
        }
        XenditConfiguration::setXenditKey($xendit_secret_key);
    }
    /**
     * Menampilkan daftar semua invoice
     * 
     * Method ini mengambil seluruh data invoice dari database dan
     * mengembalikannya dalam format JSON
     *
     * @return \Illuminate\Http\JsonResponse Response dalam format JSON yang berisi seluruh data invoice
     */
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
     *  Store a newly created resource in storage.
     * Membuat dan menyimpan invoice pembayaran menggunakan Xendit API
     * 
     * Method ini menangani pembuatan invoice baru untuk pembayaran produk menggunakan Xendit API.
     * Proses yang dilakukan meliputi:
     * 1. Mengambil data produk berdasarkan product_id yang diberikan
     * 2. Membuat invoice baru di Xendit dengan detail pembayaran
     * 3. Menyimpan data invoice ke database lokal
     * 4. Mengembalikan response dengan detail invoice yang dibuat
     *
     * @param Request $request Request object yang berisi:
     *                        - product_id: ID produk yang akan dibayar
     *                        - payer_email: Email pembayar
     *
     * @return \Illuminate\Http\JsonResponse
     *         Response berisi:
     *         - status: Status invoice (pending)
     *         - invoice_url: URL pembayaran Xendit
     *         - external_id: ID eksternal invoice
     *         - updated_at: Waktu terakhir update
     *         - created_at: Waktu pembuatan
     *
     * @throws XenditSdkException Ketika terjadi kesalahan pada API Xendit
     *         Response error berisi:
     *         - status: 'error'
     *         - message: Pesan error detail
     */
    public function store(Request $request)
    {
        //
        $apiInstance = new InvoiceApi();
        $product = Product::where('id', $request->input('product_id'))->firstOrFail();

        try {
            $createInvoiceRequest = new CreateInvoiceRequest([
                'external_id' => uniqid(),
                'amount' => $product->price,
                'payer_email' => $request->input('payer_email'),
                'description' => $product->name,
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
            $payment->product_id = $product->id;
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
    /**
     * Menangani notifikasi callback dari Xendit
     * 
     * Method ini memproses notifikasi webhook yang diterima dari Xendit.
     * Melakukan verifikasi token callback untuk keamanan dan memberikan respons yang sesuai.
     * 
     * @param Request $request Request object yang berisi data callback dari Xendit
     * @return JsonResponse Response dalam format JSON dengan status dan pesan
     * 
     * @throws Unauthorized Jika token callback tidak valid
     * 
     * Token callback diambil dari header 'X-Callback-Token' dan diverifikasi dengan
     * nilai yang tersimpan di environment variable 'XENDIT_WEBHOOK_SECRET'
     */

    public function notification(Request $request)
    {
        $callbackToken = $request->header('X-Callback-Token');

        if ($callbackToken !== env('XENDIT_WEBHOOK_SECRET')) {
            return response()->json(['status' => 'error', 'message' => 'Akses Anda ditolak, karena alasan keamanan'], 401);
        }

        $response = response()->json(['status' => 'success', 'message' => 'Token diterima'], 200);
        echo $response;
        flush();

        $payment = Invoice::where('external_id', $request->external_id)->first();

        if (!$payment) {
            return response()->json(['status' => 'error', 'message' => 'Invoice tidak ditemukan'], 404);
        }

        $invoice_callback = new XenditWebhook([
            'external_id' => $payment->external_id,
            'status' => $request->input('status'),
            'updated_at' => $request->input('updated'),
        ]);

        $payment->status = $invoice_callback->getStatus();
        $payment->updated_at = Carbon::now()->toIso8601String();
        $payment->save();

        if ($payment->status == 'PAID') {
            Log::info('Invoice has been marked as PAID.');
            return response()->json(['status' => 'success', 'message' => 'Invoice telah dibayar'], 200);
        } else {
            Log::info('Invoice has not been paid.');
            return response()->json(['status' => 'error', 'message' => 'Invoice tidak dibayar'], 200);
        }
    }
}
