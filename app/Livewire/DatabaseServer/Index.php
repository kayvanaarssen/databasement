<?php

namespace App\Livewire\DatabaseServer;

use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\TriggerBackupAction;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\View as ViewFacade;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Database Servers')]
class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?string $deleteId = null;

    #[Locked]
    public ?string $restoreId = null;

    public bool $showDeleteModal = false;

    public bool $showRedisRestoreModal = false;

    public int $deleteSnapshotCount = 0;

    public bool $keepFiles = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @param  string|array<string, mixed>  $property
     */
    public function updated(string|array $property): void
    {
        if (! is_array($property) && $property != '') {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->reset('search');
        $this->resetPage();
        $this->success(__('Filters cleared.'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-48'],
            ['key' => 'backup', 'label' => __('Backup'), 'sortable' => false, 'class' => 'overflow-hidden hidden sm:table-cell'],
            ['key' => 'jobs', 'label' => __('Jobs'), 'sortable' => false, 'class' => 'w-16 hidden sm:table-cell'],
            ['key' => 'actions', 'label' => null, 'sortable' => false, 'class' => 'w-32'],
        ];
    }

    public function confirmDelete(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('delete', $server);

        $this->deleteId = $id;
        $this->deleteSnapshotCount = $server->snapshots()->count();
        $this->keepFiles = false;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $server = DatabaseServer::findOrFail($this->deleteId);

        $this->authorize('delete', $server);

        $server->skipFileCleanup = $this->keepFiles;
        $server->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success(__('Database server deleted successfully!'));
    }

    public function confirmRestore(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('restore', $server);

        $this->restoreId = $id;

        if ($server->agent_id) {
            $this->error(__('Restore is not yet supported for agent-backed servers.'));

            return;
        }

        if ($server->database_type === DatabaseType::REDIS) {
            $this->showRedisRestoreModal = true;

            return;
        }

        $this->dispatch('open-restore-modal', targetServerId: $id);
    }

    public function openAdminer(string $id): void
    {
        $server = DatabaseServer::findOrFail($id);

        $this->authorize('view', $server);

        if (in_array($server->database_type, [DatabaseType::REDIS, DatabaseType::MONGODB])) {
            $this->error(__('Adminer does not support this database type.'), position: 'toast-bottom');

            return;
        }

        $driver = match ($server->database_type) {
            DatabaseType::MYSQL => 'server',
            DatabaseType::POSTGRESQL => 'pgsql',
            DatabaseType::SQLITE => 'sqlite',
        };

        $serverAddress = $server->database_type === DatabaseType::SQLITE
            ? ''
            : $server->host.':'.$server->port;

        $db = '';
        $databaseNames = $server->backups->first()?->database_names;
        if ($databaseNames && count($databaseNames) === 1) {
            $db = $databaseNames[0];
        }

        try {
            $password = $server->getDecryptedPassword();
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), position: 'toast-bottom');

            return;
        }

        session()->put('adminer_credentials', [
            'driver' => $driver,
            'server' => $serverAddress,
            'username' => $server->username ?? '',
            'password' => $password,
            'db' => $db,
        ]);

        $this->dispatch('open-adminer-modal',
            serverName: $server->name,
            adminerUrl: route('adminer'),
        );
    }

    public function runBackup(string $backupId, TriggerBackupAction $action): void
    {
        $backup = Backup::with(['databaseServer', 'volume', 'backupSchedule'])->findOrFail($backupId);

        $this->authorize('backup', $backup->databaseServer);

        try {
            $userId = auth()->id();
            $action->execute($backup, is_int($userId) ? $userId : null);
            $this->success(
                title: __('Backup started successfully!'),
                description: $backup->getDisplayLabel(),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), timeout: 0);
        }
    }

    public function runBackupAll(string $serverId, TriggerBackupAction $action): void
    {
        $server = DatabaseServer::with(['backups.volume', 'backups.backupSchedule'])->findOrFail($serverId);

        $this->authorize('backup', $server);

        $userId = auth()->id();
        $results = [];

        foreach ($server->backups as $backup) {
            try {
                $action->execute($backup, is_int($userId) ? $userId : null);
                $results[$backup->getDisplayLabel()] = 'success';
            } catch (\Throwable $e) {
                $results[$backup->getDisplayLabel()] = $e->getMessage();
            }
        }

        // Determine overall status
        $successCount = count(array_filter($results, fn ($v) => $v === 'success'));
        $failureCount = count(array_filter($results, fn ($v) => $v !== 'success'));

        // Build description with one line per backup config
        $description = implode("\n", array_map(function ($label, $status) {
            $icon = $status === 'success' ? '✓' : '✗';

            return "{$icon} {$label}";
        }, array_keys($results), array_values($results)));

        // Determine toast type based on results
        if ($failureCount === 0) {
            $this->success(
                title: __('All backups started successfully!'),
                description: $description,
            );
        } elseif ($successCount === 0) {
            $this->error(
                title: __('All backups failed!'),
                description: $description,
                timeout: 0
            );
        } else {
            $this->warning(
                title: __(':count backups started, :failures failed', [
                    'count' => $successCount,
                    'failures' => $failureCount,
                ]),
                description: $description,
            );
        }
    }

    public function render(): View
    {
        $servers = DatabaseServerQuery::buildFromParams(
            search: $this->search,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(10);

        // Share total count globally so it's available inside Mary UI scoped slots
        ViewFacade::share('totalNotificationChannels', NotificationChannel::count());

        return view('livewire.database-server.index', [
            'servers' => $servers,
            'headers' => $this->headers(),
        ]);
    }
}
