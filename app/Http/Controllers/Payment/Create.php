<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Access\SecretKeys;
use App\Models\Payment\Links;
use App\Models\Payment\Transactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Create extends Controller
{
    public $key = 'eecc48212f3207ea34ccde73be38243e';

    public function ref()
    {
        return Str::upper('DUN' . substr(md5(time()), 0, 15));
    }

    public function createPaymentLink(Request $request)
    {

        /**
         * amount, url, type, status, redirect_url, desc
         */

        /**
         * TYPE
         * payment page, same for store - 1
         * api payment - 2
         */

        /**
         * Check if key is passed as part of request headers
         */
        if (!$request->hasHeader('dune-sec-key')) {
            return response(['status' => 'failed', 'message' => 'Application secret key is required.'], 401);
        }

        /**
         * Check if key exists in the access_keys db table
         */
        if (!SecretKeys::where('key', $request->header('dune-sec-key'))->exists()) {
            return response(['status' => 'failed', 'message' => 'Application secret key is invalid.'], 401);
        }

        $request->validate([
            'amount' => 'required|integer',
            // 'merchant_id' => 'required',
        ]);

        $mer = SecretKeys::where('key', $request->header('dune-sec-key'))->first();

        $id = substr(md5(time()), 0, 10);

        Links::create([
            'pay_id' => $id,
            'merchant_id' => $mer->merchant_id,
            'amount' => $request->amount,
            'redirect_url' => $request->redirect_url ?? '',
            'desc' => $request->desc ?? '',
            'status' => 1
        ]);

        return response(['status' => 'success', 'link' => 'http://localhost:3000/pay/' . $id], 200);
    }

    public function getPaymentLink(Request $request, $id)
    {
        /**
         * Check if key is passed as part of request headers
         */
        if (!$request->hasHeader('dune-sec-key')) {
            return response(['status' => 'failed', 'message' => 'Application secret key is required.'], 401);
        }

        /**
         * Check if key exists in the access_keys db table
         */
        if (!SecretKeys::where('key', $request->header('dune-sec-key'))->exists()) {
            return response(['status' => 'failed', 'message' => 'Application secret key is invalid.'], 401);
        }

        if (!Links::where('pay_id', $id)->exists()) {
            return response(['status' => 'failed', 'message' => 'Payment link not found.'], 400);
        }

        if (Links::where('pay_id', $id)->sum('status') == 0) {
            return response(['status' => 'failed', 'message' => 'Payment link has been deactivated.'], 400);
        }

        $link = Links::where('pay_id', $id)->first();

        return response(['status' => 'success', 'data' => [

            'merchant_id' => $link->merchant_id,
            'pay_id' => $link->pay_id,
            'amount' => $link->amount / 100,
            'redirect_url' => $link->redirect_url ?? '',
            'desc' => $link->desc ?? '',
            'date' => Carbon::parse($link->created_at)->isoFormat('ll'),

        ]], 200);
    }

    /**
     * Payment type 1
     */
    public function authorizePayment(Request $request)
    {
        /**
         * Check if key is passed as part of request headers
         */
        if (!$request->hasHeader('dune-sec-key')) {
            return response(['status' => 'failed', 'message' => 'Application secret key is required.'], 401);
        }

        /**
         * Check if key exists in the access_keys db table
         */
        if (!SecretKeys::where('key', $request->header('dune-sec-key'))->exists()) {
            return response(['status' => 'failed', 'message' => 'Application secret key is invalid.'], 401);
        }

        // return response($request->header('dune-sec-key'));

        /** 
         * Validate request data
         */
        $request->validate([
            // 'amount' => 'required|integer',
            'email' => 'required',
            'password' => 'required',
            'destination_wallet_alias' => 'required',
            'narration' => 'required',
            'pay_id' => 'required'
        ]);

        if (!Links::where('pay_id', $request->pay_id)->exists()) {
            return response(['status' => 'failed', 'message' => 'Payment link is invalid.'], 400);
        }

        $link = Links::where('pay_id', $request->pay_id)->first();

        /**
         * Get user auth token
         */
        $data = Http::withHeaders(['ClientId' => $this->key])
            ->withOptions(['verify' => false])
            ->post('https://rgw.k8s.apis.ng/centric-platforms/uat/CAMLLogin', [
                'user_id' => $request->email,
                'password' => $request->password,
                'allow_tokenization' => 'Y',
                'user_type' => 'USER',
                'channel_code' => 'APISNG'
            ]);

        if ($data->status() === 200) {
            // check the response message 
            $res = $data->object();

            // return response(['data' => $res]);

            if ($res->response_code == 00) {
                /** Initiate payment */
                return $this->initiatePayment($res->response_data, $request->all(), $link->amount);
            }

            /**
             * If the response_code is not login_successful, this is a second layer to confirm the message
             * received from the server.
             */
            if ($res->response_code != 00) {
                return response(['status' => 'error', 'message' => $res->response_message], 400);
            }
        }

        /** 
         * if other status code apart from 200
         */
        if ($data->status() !== 200) {
            $res = $data->object();
            return response(['status' => 'failed', 'message' => $res->error], 400);
        }
    }

    public function initiatePayment($response_data, $req, $amount)
    {
        /**
         * Iniitate payment
         */

        /** 
         * Let's hardcode the receiving user for now 
         */

        $data = Http::withHeaders(['ClientId' => $this->key])
            ->withOptions(['verify' => false])
            ->post('https://rgw.k8s.apis.ng/centric-platforms/uat/PaymentFromWallet', [
                "channel_code" => "APISNG",
                // "user_email" => $req['email'],
                "user_email" => "test_user+access@bitt.com",
                "user_type" => "USER",
                "destination_wallet_alias" => $req['destination_wallet_alias'],
                "amount" => $amount / 100,
                "reference" => 'DUN' . substr(md5(time()), 0, 15),
                "narration" => $req['narration']
            ]);

        /** 
         * Handle Errors 
         */
        if ($data->status() === 200) {
            $res = $data->object();

            if ($res->response_code == 00) {
                /** Create transaction */

                $data = (object)[
                    'reference' => $res->response_data->walletTransferTransactionReference,
                    'pay_id' => $req['pay_id'],
                    'amount' => $amount,
                    'customer' => $req['email'],
                ];

                return $this->createTransaction($data);
            }


            if ($res->response_code == 99) {
                /** Return insufficient funds */
                return response(['status' => 'error', 'message' => $res->response_data->Data->message], 400);
            }

            /**
             * If the response_code is not login_successful, this is a second layer to confirm the message
             * received from the server.
             */
            if ($res->response_code != 00) {
                return response(['status' => 'error', 'message' => $res->response_message], 400);
            }
        }

        if ($data->status() !== 200) {
            $res = $data->object();
            return response(['status' => 'failed', 'message' => $res->response_message], 400);
        }

        return response(['data' => $data->object()], 200);
    }

    /**
     * Payment type 2
     * Invoice / Pin
     */
    public function paymentTypeTwo(Request $request)
    {
        /**
         * Check if key is passed as part of request headers
         */
        if (!$request->hasHeader('dune-sec-key')) {
            return response(['status' => 'failed', 'message' => 'Application secret key is required.'], 401);
        }

        /**
         * Check if key exists in the access_keys db table
         */
        if (!SecretKeys::where('key', $request->header('dune-sec-key'))->exists()) {
            return response(['status' => 'failed', 'message' => 'Application secret key is invalid.'], 401);
        }

        $request->validate([
            // 'amount' => 'required',
            // 'narration' => 'required',
            'pay_id' => 'required',
            'phone' => 'required|max:11|min:11',
            'pin' => 'required|min:4|max:4'
        ]);

        if (!Links::where('pay_id', $request->pay_id)->exists()) {
            return response(['status' => 'failed', 'message' => 'Pay ID is invalid.'], 400);
        }

        $link = Links::where('pay_id', $request->pay_id)->first();

        $data = Http::withHeaders(['ClientId' => $this->key])
            ->withOptions(['verify' => false])
            ->post('https://rgw.k8s.apis.ng/centric-platforms/uat/CreateInvoice', [
                "amount" => $link->amount / 100,
                "narration" => $request->narration ?? $request->pay_id,
                "reference" => $this->ref(),
                // product code here can be our product code for stores
                "product_code" => "001",
                "channel_code" => "APISNG"
            ]);


        if ($data->status() === 200) {
            $res = $data->object();

            if ($res->response_code == 00) {
                /** Initiate payment */
                // return response(['data' => $res->response_data]);
                return $this->completePaymentTwo($request->all(), $res->response_data, $link);
            }

            if ($res->response_code != 00) {
                return response(['status' => 'error', 'message' => $res->response_message], 400);
            }
        }

        /** 
         * if other status code apart from 200
         */
        if ($data->status() !== 200) {
            $res = $data->object();
            return response(['status' => 'failed', 'message' => $res->error], 400);
        }
    }

    public function completePaymentTwo($req, $rdata, $link)
    {
        // return response(['data' => $data]);
        $data = Http::withHeaders(['ClientId' => $this->key])
            ->withOptions(['verify' => false])
            ->post('https://rgw.k8s.apis.ng/centric-platforms/uat/PayWithTransactionPin', [
                "channel_code" => "APISNG",
                "phone_number" => $req['phone'],
                "amount" => $link->amount / 100,
                "reference" => $rdata->paymentId,
                "transaction_pin" => $req['pin'],
                "invoice_id" => $rdata->guid,
                // provide product id for store products
                "product_code" => "001"
            ]);

        if ($data->status() === 200) {
            $res = $data->object();

            if ($res->response_code == 00) {
                /** Create transaction */

                // return response(['data' => $res->json()]);

                $data = (object)[
                    'reference' => $rdata->paymentId,
                    'pay_id' => $req['pay_id'],
                    'amount' => $link->amount,
                    'customer' => $req['email'] ?? $req['phone'],
                ];

                return $this->createTransaction($data);
            }


            if ($res->response_code == 99) {
                /** Return incorrect amount  */
                return response(['status' => 'error', 'message' => $res->response_data->Data->error], 400);
            }

            /**
             * If the response_code is not login_successful, this is a second layer to confirm the message
             * received from the server.
             */
            if ($res->response_code != 00) {
                return response(['status' => 'error', 'message' => $res->response_message], 400);
            }
        }

        if ($data->status() !== 200) {
            $res = $data->object();
            return response(['status' => 'failed', 'message' => $res->response_message], 400);
        }

        return response(['data' => $data->object()], 200);


    }

    public function createTransaction($data)
    {
        $merchant = Links::where('pay_id', $data->pay_id)->sum('merchant_id');

        Transactions::create([
            'merchant_id' => $merchant,
            'reference' => $data->reference,
            'pay_id' => $data->pay_id,
            'amount' => $data->amount,
            'customer' => $data->customer,
        ]);

        return response(['status' => 'success', 'message' => 'Transaction completed successfully.'], 200);
    }

    /**
     * Get payment links
     */
    public function getPaymentLinks(Request $request, $id) {
        /**
         * Check if key is passed as part of request headers
         */
        if (!$request->hasHeader('dune-sec-key')) {
            return response(['status' => 'failed', 'message' => 'Application secret key is required.'], 401);
        }

        /**
         * Check if key exists in the access_keys db table
         */
        if (!SecretKeys::where('key', $request->header('dune-sec-key'))->exists()) {
            return response(['status' => 'failed', 'message' => 'Application secret key is invalid.'], 401);
        }
        
        return response(['message' => 'success', 'data' => Links::where('merchant_id', $id)->get()], 200);
    }
}
