<?php

namespace Fixture\App\Http\Controllers;

use Fixture\App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** No controller middleware: whatever auth these actions have, they do themselves. */
class AccountController
{
    public function mine(Request $request)
    {
        if (! auth('api')->user()) {
            return response()->json(['errors' => [['code' => 'auth', 'message' => 'Unauthorized']]], 401);
        }

        return response()->json(['orders' => []]);
    }

    public function favourite(Request $request)
    {
        $user = $request->user();
        if ($request->boolean('save')) {
            if ($user === null) {
                return response()->json(['message' => 'Sign in to save'], 403);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function personalised(Request $request)
    {
        return response()->json(['for' => auth('api')->id()]);
    }

    public function signIn(Request $request)
    {
        $account = Account::where('email', $request->input('email'))->first();
        if (! $account || ! Hash::check($request->input('password'), $account->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return response()->json(['token' => 'issued']);
    }

    public function guardLogin(Request $request)
    {
        if (auth('courier')->attempt($request->only('phone', 'password'))) {
            if (auth('courier')->user()->status !== 'approved') {
                return response()->json(['message' => 'Pending approval'], 401);
            }

            return response()->json(['token' => 'issued']);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }
}
