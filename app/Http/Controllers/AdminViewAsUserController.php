<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminViewAsUserService;
use Illuminate\Http\RedirectResponse;

class AdminViewAsUserController extends Controller
{
    public function exit(AdminViewAsUserService $viewAs): RedirectResponse
    {
        return $viewAs->exit();
    }
}
