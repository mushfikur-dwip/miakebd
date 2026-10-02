<?php

namespace Tests\Feature;

use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Company name, mail settings and the licence key are copied into .env. The
 * env editor wraps a value with spaces in quotes but never escapes it, so an
 * honest name like Suglow "BD" left .env unparseable - every page a 500 - and
 * a crafted one could append lines (APP_DEBUG=true) or pull ${DB_PASSWORD}
 * into a setting that is displayed.
 *
 * All writes go to a temporary copy of .env.example, never the real file.
 */
class EnvWriteSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $env;
    private string $envBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = tempnam(sys_get_temp_dir(), 'env');
        copy(base_path('.env.example'), $this->env);
        $this->envBefore = file_get_contents($this->env);
        config(['enveditor.pathToEnv' => $this->env]);

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        Permission::findOrCreate('settings', 'sanctum');
        Role::findById(EnumRole::ADMIN, 'sanctum')->givePermissionTo('settings');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::create([
            'name'         => 'Owner',
            'username'     => 'owner',
            'email'        => 'owner@example.com',
            'password'     => bcrypt('secret123'),
            'phone'        => '01700000001',
            'country_code' => '+880',
            'status'       => Status::ACTIVE,
        ]);
        $admin->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($admin);
    }

    protected function tearDown(): void
    {
        @unlink($this->env);

        parent::tearDown();
    }

    private function company(string $name): array
    {
        return [
            'company_name'         => $name,
            'company_email'        => 'support@suglow.com',
            'company_calling_code' => '+880',
            'company_phone'        => '1709786330',
            'company_city'         => 'Rangpur',
            'company_state'        => 'Rangpur',
            'company_country_code' => 'BGD',
            'company_zip_code'     => '5400',
            'company_address'      => 'Shop 52, RAMC Shopping Complex',
            // Optional in the request, but CompanyResource reads every key back.
            'company_website'      => 'https://suglow.com',
            'company_latitude'     => '25.74',
            'company_longitude'    => '89.25',
        ];
    }

    public function test_company_name_with_a_quote_is_refused_and_env_untouched(): void
    {
        $this->putJson('/api/admin/setting/company', $this->company('Suglow "BD"'))
            ->assertStatus(422)
            ->assertJsonPath('errors.company_name.0', 'The company name may not contain quotes, backslashes, line breaks or ${…}.');

        $this->assertSame($this->envBefore, file_get_contents($this->env));
    }

    public function test_plain_company_name_is_written(): void
    {
        $this->putJson('/api/admin/setting/company', $this->company('Suglow BD'))->assertOk();

        $this->assertStringContainsString('APP_NAME="Suglow BD"', file_get_contents($this->env));
    }

    public function test_rule_flags_each_character_that_breaks_env(): void
    {
        foreach (['a"b', 'a\\b', "a\rb", "a\nb", "a\0b", 'a${B}b'] as $unsafe) {
            $this->assertFalse(\App\Rules\EnvSafe::isSafe($unsafe), json_encode($unsafe));
        }
        // Real-world values that must still be accepted.
        foreach (['Suglow BD', 'smtp.gmail.com', 'p@ss#w0rd$1', "O'Brien", '', null] as $safe) {
            $this->assertTrue(\App\Rules\EnvSafe::isSafe($safe), json_encode($safe));
        }
    }

    public function test_env_editor_refuses_a_value_that_adds_a_line(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            app(EnvEditor::class)->addData(['APP_NAME' => "x\nAPP_DEBUG=true"]);
        } finally {
            $this->assertSame($this->envBefore, file_get_contents($this->env));
        }
    }

    public function test_env_editor_refuses_a_value_that_reads_another_variable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            app(EnvEditor::class)->addData(['APP_NAME' => '${DB_PASSWORD}']);
        } finally {
            $this->assertSame($this->envBefore, file_get_contents($this->env));
        }
    }
}
