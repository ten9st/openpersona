<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;

class PublicProfileController extends Controller
{
    public function show(Request $request, User $user)
    {
        return response()->json(
            PublicProfilePresenter::detail($user)
        );
    }
}
