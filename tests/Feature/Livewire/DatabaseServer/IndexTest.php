<?php

use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Livewire\DatabaseServer\Index;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

test('runBackup triggers backup for a specific backup configuration', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runBackup includes backup display label in success toast', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id);

    // Verify a backup job was created
    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('runBackup fails with authorization error if user is viewer', function () {
    $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->selected(['test_db'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('runBackup', $backup->id)
        ->assertForbidden();
});

// --- openAdminer ---

test('openAdminer does nothing when adminer is disabled', function () {
    AppConfig::set('app.adminer_enabled', false);

    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertNotDispatched('open-adminer-modal');
});

test('openAdminer rejects unsupported database types', function (string $factoryState) {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->{$factoryState}()->withoutBackups()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertNotDispatched('open-adminer-modal');
})->with([
    'redis' => ['redis'],
    'mongodb' => ['mongodb'],
]);

test('openAdminer maps correct driver and credentials for MySQL', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'database_type' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
        'username' => 'admin',
        'password' => 'secret',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertDispatched('open-adminer-modal');

    expect(session('adminer_credentials'))->toMatchArray([
        'driver' => 'server',
        'server' => 'db.example.com:3306',
        'username' => 'admin',
        'password' => 'secret',
        'db' => '',
    ]);
});

test('openAdminer maps pgsql driver for PostgreSQL', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'postgres']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertDispatched('open-adminer-modal');

    expect(session('adminer_credentials')['driver'])->toBe('pgsql');
});

test('openAdminer auto-selects database when backup has exactly one', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);
    Backup::factory()->for($server)->selected(['mydb'])->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertDispatched('open-adminer-modal');

    expect(session('adminer_credentials')['db'])->toBe('mydb');
});

test('openAdminer shows error when password decryption fails', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    // Corrupt the encrypted password to trigger DecryptException
    DB::table('database_servers')->where('id', $server->id)->update(['password' => 'corrupted']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openAdminer', $server->id)
        ->assertNotDispatched('open-adminer-modal');
});
