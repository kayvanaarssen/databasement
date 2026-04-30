<?php

namespace App\Http\Controllers\Web;

use App\Facades\AppConfig;
use App\Http\Controllers\Controller;
use App\Services\AdminerService;
use Illuminate\Http\Request;

class AdminerController extends Controller
{
    public function __invoke(Request $request, AdminerService $adminer): void
    {
        abort_unless((bool) AppConfig::get('app.adminer_enabled'), 404);

        $credentials = session('adminer_credentials');

        // Auto-login: simulate form submission only on the initial load
        // (before Adminer redirects with its own query parameters)
        $isInitialLoad = ! isset($_GET['server']) && ! isset($_GET['pgsql'])
            && ! isset($_GET['sqlite']) && ! isset($_GET['mongo']);

        if ($credentials && $isInitialLoad && ! isset($_POST['auth'])) {
            $_POST['auth'] = [
                'driver' => $credentials['driver'],
                'server' => $credentials['server'],
                'username' => $credentials['username'],
                'password' => $credentials['password'],
                'db' => $credentials['db'] ?? '',
            ];
        }

        // Release the session lock before Adminer runs. Adminer is long-lived
        // and loads sub-resources (CSS/JS) through this same route — holding
        // the lock would block those requests and cause timeouts.
        session()->save();

        $adminer->render($credentials);
    }
}
