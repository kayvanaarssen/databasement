<?php

namespace App\Services;

class AdminerService
{
    /**
     * Serve Adminer with auto-login credentials and custom CSS.
     *
     * @param  array<string, string>|null  $credentials
     */
    public function render(?array $credentials): void
    {
        $vendorAdminer = base_path('vendor/dg/adminer-custom');

        $GLOBALS['_adminer_credentials'] = $credentials;
        $GLOBALS['_adminer_css_path'] = $this->resolveViteCssPath();

        if ($this->serveStaticFile($vendorAdminer)) {
            return;
        }

        $this->useNonLockingSessionHandler();
        $this->startOutputBuffering();
        $this->defineAdminerObject();

        chdir($vendorAdminer);
        include $vendorAdminer.'/adminer.php';
    }

    /**
     * Resolve the Vite-built adminer CSS path from the build manifest.
     */
    private function resolveViteCssPath(): string
    {
        $manifestPath = public_path('build/manifest.json');

        if (file_exists($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            if (isset($manifest['resources/css/adminer.css']['file'])) {
                return '/build/'.$manifest['resources/css/adminer.css']['file'];
            }
        }

        return '/css/adminer.css';
    }

    /**
     * Serve static files (JS, images) from the vendor package.
     * Returns true if a file was served (and execution should stop).
     */
    private function serveStaticFile(string $vendorAdminer): bool
    {
        if (empty($_GET['file'])) {
            return false;
        }

        if (! preg_match('#^(default|adminer|static(/\w[\w.-]*)+)\.(\w+)\z#', $_GET['file'], $m)) {
            return false;
        }

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

    /**
     * Register a non-locking session handler so Adminer's session_start()
     * doesn't block concurrent requests (navigations, sub-resources).
     * The default file handler uses flock(), causing timeouts when
     * multiple Adminer requests overlap for the same session.
     */
    private function useNonLockingSessionHandler(): void
    {
        $savePath = ini_get('session.save_path') ?: sys_get_temp_dir();

        session_set_save_handler(new class($savePath) implements \SessionHandlerInterface
        {
            public function __construct(private string $savePath) {}

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string
            {
                $file = $this->savePath.'/sess_'.$id;

                return (string) @file_get_contents($file);
            }

            public function write(string $id, string $data): bool
            {
                $file = $this->savePath.'/sess_'.$id;

                return file_put_contents($file, $data) !== false;
            }

            public function destroy(string $id): bool
            {
                $file = $this->savePath.'/sess_'.$id;
                if (file_exists($file)) {
                    @unlink($file);
                }

                return true;
            }

            public function gc(int $max_lifetime): int
            {
                $files = glob($this->savePath.'/sess_*') ?: [];
                foreach ($files as $file) {
                    if (filemtime($file) + $max_lifetime < time()) {
                        @unlink($file);
                    }
                }

                return 0;
            }
        });
    }

    /**
     * Start output buffering to strip the default adminer.css link tag
     * (our Vite bundle replaces it) and rewrite static asset URLs.
     */
    private function startOutputBuffering(): void
    {
        define('ASSETS_VERSION', '1');

        ob_start(function ($s) {
            $s = preg_replace('#<link[^>]*href="[^"]*adminer\.css[^"]*"[^>]*/?\s*>#', '', $s);

            return preg_replace_callback(
                '#(<(link|script)\s[^>]*(href|src)=")(static/.+)(\?v=\d+)?"#U',
                function ($m) {
                    return $m[1].'?file='.urlencode($m[4]).'&amp;version='.ASSETS_VERSION.'"';
                },
                $s,
            );
        }, 4096);
    }

    /**
     * Define the global adminer_object() function that Adminer calls
     * to get its configuration (auto-login, iframe headers, custom CSS).
     * Must be in the root namespace — loaded from a separate file.
     */
    private function defineAdminerObject(): void
    {
        require_once __DIR__.'/adminer_object.php';
    }
}
