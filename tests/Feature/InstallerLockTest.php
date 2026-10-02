<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The web installer must be unreachable once the shop is installed.
 *
 * The controller used to guard itself with Redirect::to(...)->send() in its
 * constructor. send() flushes the redirect and returns, so PHP carried on into
 * the action: anyone could post the database step and point the live .env at
 * a server of their choosing, or re-run the final step with a plain GET.
 *
 * Everything here runs against a throwaway storage directory and .env, so the
 * failing (pre-fix) runs cannot touch the developer's real files.
 */
class InstallerLockTest extends TestCase
{
    private const ENV = "APP_NAME=Suglow\nAPP_URL=https://suglow.com\nDB_HOST=localhost\nVITE_API_KEY=real-key\n";
    private const STAMP = "Installed on 2026-01-01 10:00:00 AM\n";

    private string $storage;
    private string $realEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realEnv = (string) @file_get_contents(base_path('.env'));

        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-lock-' . uniqid();
        File::ensureDirectoryExists($this->storage);
        $this->app->useStoragePath($this->storage);
        file_put_contents($this->storage . '/.env', self::ENV);

        config([
            'enveditor.pathToEnv' => $this->storage . '/.env',
            // finalSetup() runs storage:link; with no links configured it is a no-op.
            'filesystems.links'   => [],
        ]);

        // The licence server answers "valid", so the licence step would reach its .env write.
        Http::fake(['*' => Http::response(['status' => true, 'message' => 'ok'], 200, ['Content-Type' => 'application/json'])]);
    }

    protected function tearDown(): void
    {
        // Whatever happened above, the developer's own .env survives.
        if ((string) @file_get_contents(base_path('.env')) !== $this->realEnv) {
            file_put_contents(base_path('.env'), $this->realEnv);
        }
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function markInstalled(): void
    {
        file_put_contents($this->storage . '/installed', self::STAMP);
    }

    private function env(): string
    {
        return (string) file_get_contents($this->storage . '/.env');
    }

    public function test_a_fresh_install_can_still_reach_the_installer(): void
    {
        $this->get('/install')->assertOk();
    }

    public function test_installer_pages_are_gone_once_installed(): void
    {
        $this->markInstalled();

        foreach (['/install', '/install/requirement', '/install/permission', '/install/license',
                  '/install/site', '/install/database', '/install/final'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_site_step_cannot_rewrite_env_once_installed(): void
    {
        $this->markInstalled();

        $this->post('/install/site', ['app_name' => 'Evil', 'app_url' => 'https://evil.example'])->assertNotFound();

        $this->assertSame(self::ENV, $this->env());
    }

    public function test_licence_step_cannot_rewrite_env_once_installed(): void
    {
        $this->markInstalled();

        $this->post('/install/license', ['license_key' => 'attacker-key'])->assertNotFound();

        $this->assertSame(self::ENV, $this->env());
    }

    public function test_database_step_cannot_run_once_installed(): void
    {
        $this->markInstalled();

        $this->post('/install/database', [
            'database_host'     => '127.0.0.1',
            'database_port'     => 1,
            'database_name'     => 'attacker',
            'database_username' => 'attacker',
            'database_password' => 'attacker',
        ])->assertNotFound();

        $this->assertSame(self::ENV, $this->env());
    }

    public function test_final_step_cannot_run_once_installed(): void
    {
        $this->markInstalled();

        $this->get('/install/final-store')->assertNotFound();

        $this->assertSame(self::STAMP, file_get_contents($this->storage . '/installed'));
        $this->assertSame(self::ENV, $this->env());
    }
}
