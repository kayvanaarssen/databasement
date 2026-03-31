<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminerController extends Controller
{
    public function __invoke(Request $request): void
    {
        $credentials = session('adminer_credentials');
        $GLOBALS['_adminer_credentials'] = $credentials;

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

        require resource_path('adminer/index.php');
    }
}
