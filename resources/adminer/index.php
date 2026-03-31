<?php

/**
 * Adminer entry point — included by AdminerController.
 *
 * Handles static file serving, auto-login via credentials
 * passed through $GLOBALS['_adminer_credentials'], and
 * iframe embedding (X-Frame-Options: SAMEORIGIN).
 */
$vendorAdminer = base_path('vendor/dg/adminer-custom');

// Resolve the Vite-built adminer CSS path from manifest
$adminerCssPath = '/css/adminer.css';
$manifestPath = public_path('build/manifest.json');
if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (isset($manifest['resources/css/adminer.css']['file'])) {
        $adminerCssPath = '/build/'.$manifest['resources/css/adminer.css']['file'];
    }
}

// Serve static files (CSS, JS, images) from the vendor package
if (! empty($_GET['file'])) {
    if (preg_match('#^(default|adminer|static(/\w[\w.-]*)+)\.(\w+)\z#', $_GET['file'], $m)) {
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        header('Expires: '.gmdate('D, d M Y H:i:s', strtotime('1 month')).' GMT');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');

        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'gif' => 'image/gif', 'png' => 'image/png'];
        if (isset($types[$m[3]])) {
            header('Content-Type: '.$types[$m[3]]);
        }

        @readfile($vendorAdminer.'/'.$_GET['file']);
        exit;
    }
}

define('ASSETS_VERSION', '1');

// Strip the default adminer.css <link> (our Vite bundle already includes it)
// and rewrite other static asset URLs to use ?file= parameter
ob_start(function ($s) {
    // Remove the base adminer.css link tag — our Vite bundle replaces it
    $s = preg_replace('#<link[^>]*href="[^"]*adminer\.css[^"]*"[^>]*/?\s*>#', '', $s);

    // Rewrite remaining static assets (JS, images) to use ?file= parameter
    return preg_replace_callback(
        '#(<(link|script)\s[^>]*(href|src)=")(static/.+)(\?v=\d+)?"#U',
        function ($m) {
            return $m[1].'?file='.urlencode($m[4]).'&amp;version='.ASSETS_VERSION.'"';
        },
        $s,
    );
}, 4096);

// Auto-login and iframe embedding
function adminer_object()
{
    $credentials = $GLOBALS['_adminer_credentials'] ?? null;
    $cssPath = $GLOBALS['_adminer_css_path'] ?? '/css/adminer.css';

    return new class($credentials, $cssPath) extends \Adminer\Adminer
    {
        /** @var array<string, string>|null */
        private ?array $creds;

        private string $cssPath;

        /** @param array<string, string>|null $creds */
        public function __construct(?array $creds, string $cssPath)
        {
            $this->creds = $creds;
            $this->cssPath = $cssPath;
        }

        /** @return array{string, string, string} */
        public function credentials(): array
        {
            if ($this->creds) {
                return [$this->creds['server'], $this->creds['username'], $this->creds['password']];
            }

            return ['', '', ''];
        }

        public function login($login, $password)
        {
            return $this->creds !== null;
        }

        public function headers()
        {
            header('X-Frame-Options: SAMEORIGIN');
        }

        /** @return array{string} */
        public function css()
        {
            return [$this->cssPath];
        }
    };
}

$GLOBALS['_adminer_css_path'] = $adminerCssPath;

chdir($vendorAdminer);
include $vendorAdminer.'/adminer.php';
