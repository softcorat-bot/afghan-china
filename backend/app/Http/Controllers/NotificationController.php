<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Tenant;
use Illuminate\Http\Request;

class NotificationController extends Controller {
    public function index() {
        return response()->json(
            Notification::where('company_id', Tenant::id())
                ->latest()->limit(30)->get()
        );
    }
    public function markRead() {
        Notification::where('company_id', Tenant::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
