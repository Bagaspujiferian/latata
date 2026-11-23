<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function requireUser(Request $request): User
    {
        $user = $request->user();
        if (! ($user instanceof User)) {
            abort(401, 'Silakan masuk ke akun Anda terlebih dahulu.');
        }

        return $user;
    }
}
