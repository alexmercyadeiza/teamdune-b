<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Access\SecretKeys;
use Illuminate\Http\Request;

class SecretKey extends Controller
{
    public function createSecretKey(Request $request)
    {
        $request->validate([
            'merchant_id' => 'required|unique:secret_keys,merchant_id'
        ]);

        $key = SecretKeys::create([
            'merchant_id' => $request->merchant_id,
            'key' => 'live_sk_' . substr(md5(time()), 0, 24),
        ]);

        return response(['status' => 'success', 'message' => 'Merchant app-key created.', 'key' => $key->key], 200);
    }
}
