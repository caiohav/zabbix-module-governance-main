<?php

namespace Modules\Governance;

use RuntimeException;
use Throwable;

/** A public, path-free storage error; arbitrary exception messages are never sent to the browser. */
final class AvailabilityJobException extends RuntimeException {}

/**
 * Short-lived checkpoints, not a durable queue or a monthly archive.
 *
 * The PHP worker needs a private, writable temporary directory on a local
 * filesystem with flock() and atomic rename(). Frontends on different machines
 * need sticky routing. Checkpoints are isolated by module installation and user;
 * processes running under the same OS account remain inside the trust boundary.
 * Creating another job may evict the owner's oldest terminal checkpoint at quota;
 * running or locked jobs are never evicted to make room.
 * Fixed lock shards are deliberately never deleted: removing a lock file while
 * another process has it open would permit two writers after an atomic rename.
 */
final class AvailabilityJobStore {
    const IDLE_TTL = 3600;
    const ABSOLUTE_TTL = 7200;
    const MAX_JOB_BYTES = 16777216;
    const MAX_OWNER_JOBS = 4;
    const MAX_JOBS = 32;
    const BUSY = 1;
    const UNAVAILABLE = 2;
    const INVALID = 3;
    const CAPACITY = 4;
    const STORAGE = 5;
    const PAYLOAD = 6;
    const MEMORY = 7;

    private $directory;
    private $clock;

    // The optional directory and clock are server-side test seams, never request inputs.
    public function __construct(?string $directory = null, ?callable $clock = null) {
        $this->clock = $clock ?? static function(): int { return time(); };
        $directory = $directory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'zabbix-governance-availability-' . substr(hash('sha256', __DIR__), 0, 24);
        $parent = realpath(dirname($directory));
        $name = basename($directory);
        if ($parent === false || $name === '' || $name === '.' || $name === '..') {
            throw self::storageError();
        }
        $this->directory = $parent . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            @mkdir($this->directory, 0700);
        }
        clearstatcache(true, $this->directory);
        $stat = @lstat($this->directory);
        if (!$stat || ($stat['mode'] & 0170000) !== 0040000 || is_link($this->directory)
                || !$this->privateOwner($stat) || !is_writable($this->directory)) {
            throw self::storageError();
        }
    }

    public function create(string $owner, string $requestId, callable $initializer): array {
        self::validateOwner($owner);
        self::validateId($requestId);
        $id = hash('sha256', $owner . ':' . $requestId);
        // Creation and quota accounting are one operation, including concurrent tabs.
        return $this->locked('.create.lock', LOCK_EX, function() use ($owner, $id, $initializer): array {
            $existing = $this->locked($this->lockName($id), LOCK_SH, function() use ($owner, $id) {
                $path = $this->path($id, $owner);
                return file_exists($path) || is_link($path) ? $this->load($id, $owner) : null;
            });
            if ($existing !== null) { return $existing; }
            $this->collectGarbage();
            $jobs = $this->jobFiles();
            $this->makeRoom($jobs, $owner);
            $jobs = $this->jobFiles();
            $owned = 0;
            foreach ($jobs as $job) { if ($job['owner'] === $owner) { $owned++; } }
            if (count($jobs) >= self::MAX_JOBS || $owned >= self::MAX_OWNER_JOBS) {
                throw new AvailabilityJobException(
                    'Temporary calculation capacity reached. Resume a pending calculation or wait for expiration / Limite de cálculos temporários atingido. Retome um cálculo pendente ou aguarde a expiração.', self::CAPACITY
                );
            }
            return $this->locked($this->lockName($id), LOCK_EX, function() use ($owner, $id, $initializer): array {
                try {
                    $state = $initializer();
                    $this->validateState($state);
                }
                catch (Throwable $e) {
                    // Persist failed starts too: replaying a lost response must not rerun discovery.
                    $state = self::terminalState([], 'failed',
                        'Cannot start calculation. Check the month, department and saved rules / Não foi possível iniciar o cálculo. Confira o mês, departamento e as regras salvas.');
                }
                $now = $this->now();
                $job = ['id' => $id, 'owner' => $owner, 'sequence' => 0,
                    'created_at' => $now, 'updated_at' => $now, 'state' => $state];
                return $this->checkpoint($job);
            });
        });
    }

    public function read(string $id, string $owner): array {
        self::validateOwner($owner);
        self::validateId($id);
        return $this->locked($this->lockName($id), LOCK_SH, function() use ($id, $owner): array {
            return $this->load($id, $owner);
        });
    }

    public function step(string $id, string $owner, int $expectedSequence, callable $advance): array {
        return $this->change($id, $owner, $expectedSequence, function(array $state) use ($advance): array {
            try {
                $next = $advance($state);
                $this->validateState($next);
                return $next;
            }
            catch (Throwable $e) {
                return self::terminalState($state, 'failed',
                    'Calculation failed; no partial result was published. Start a new calculation / Falha no cálculo; nenhum resultado parcial foi publicado. Inicie um novo cálculo.');
            }
        });
    }

    public function cancel(string $id, string $owner, int $expectedSequence): array {
        return $this->change($id, $owner, $expectedSequence, static function(array $state): array {
            return self::terminalState($state, 'cancelled');
        });
    }

    /** Only this allowlist can leave the server. Timelines, samples and rules stay private. */
    public static function projection(array $job): array {
        $state = $job['state'];
        $progress = [];
        foreach (['hosts_total', 'hosts_done', 'checks_total', 'checks_done', 'slas_total', 'slas_done', 'rows', 'calls'] as $field) {
            $progress[$field] = max(0, (int) ($state['progress'][$field] ?? 0));
        }
        $progress['percent'] = max(0, min(100, (float) ($state['progress']['percent'] ?? 0)));
        foreach (['stage', 'department', 'technology', 'host'] as $field) {
            $progress[$field] = is_string($state['progress'][$field] ?? null)
                ? mb_substr($state['progress'][$field], 0, 200, 'UTF-8') : '';
        }
        $report = $state['report'] ?? [];
        $department = (int) ($state['department_filter'] ?? -1);
        $projection = ['job' => $job['id'], 'sequence' => $job['sequence'], 'status' => $state['status'],
            'progress' => $progress,
            'snapshot' => [
                'month' => $report['month'] ?? '', 'department' => $department,
                'department_name' => $state['source_config']['departments'][$department]['name'] ?? '',
                'from' => $report['from'] ?? null, 'to' => $report['to'] ?? null,
                'timezone' => $report['timezone'] ?? ($state['source_config']['timezone'] ?? ''),
                'data_policy' => $report['data_policy'] ?? ($state['source_config']['data_policy'] ?? 'strict'),
                'generated_at' => $report['generated_at'] ?? $job['created_at']
            ]];
        if ($state['status'] === 'failed') {
            $projection['error'] = is_string($state['error'] ?? null) ? mb_substr($state['error'], 0, 1000, 'UTF-8')
                : 'Calculation failed / Falha no cálculo.';
        }
        if ($state['status'] === 'complete') {
            $projection['result_url'] = 'zabbix.php?action=governance.availability.view&job=' . $job['id'];
        }
        return $projection;
    }

    private function change(string $id, string $owner, int $sequence, callable $change): array {
        self::validateOwner($owner);
        self::validateId($id);
        if ($sequence < 0) { throw self::invalidError(); }
        return $this->locked($this->lockName($id), LOCK_EX, function() use ($id, $owner, $sequence, $change): array {
            $job = $this->load($id, $owner);
            if ($sequence > $job['sequence']) { throw self::invalidError(); }
            // A retried/late request observes the checkpoint already committed, without reapplying it.
            if ($sequence < $job['sequence'] || $job['state']['status'] !== 'running') { return $job; }
            $job['state'] = $change($job['state']);
            $job['sequence']++;
            $job['updated_at'] = $this->now();
            return $this->checkpoint($job);
        });
    }

    private function checkpoint(array $job): array {
        try { $this->save($job); }
        catch (AvailabilityJobException $e) {
            if ($e->getCode() !== self::PAYLOAD) { throw $e; }
            $job['state'] = self::terminalState($job['state'], 'failed',
                'Calculation exceeds safe checkpoint storage; reduce the scope / O cálculo excede o armazenamento seguro de etapas; reduza o escopo.');
            $this->save($job);
        }
        return $job;
    }

    private static function terminalState(array $state, string $status, ?string $error = null): array {
        // Drop partial report content and work arrays; retain only frozen labels and progress.
        $report = array_intersect_key($state['report'] ?? [], array_flip([
            'month', 'timezone', 'from', 'to', 'generated_at', 'partial', 'data_policy'
        ]));
        $terminal = ['status' => $status, 'source_config' => $state['source_config'] ?? [],
            'department_filter' => $state['department_filter'] ?? -1, 'report' => $report,
            'progress' => $state['progress'] ?? []];
        $terminal['progress']['stage'] = $status;
        if ($error !== null) { $terminal['error'] = $error; }
        return $terminal;
    }

    private function validateState($state): void {
        if (!is_array($state) || !in_array($state['status'] ?? '', ['running', 'complete', 'failed', 'cancelled'], true)
                || !is_array($state['progress'] ?? null)) {
            throw self::invalidError();
        }
    }

    private function load(string $id, string $owner): array {
        $path = $this->path($id, $owner);
        if (!file_exists($path) && !is_link($path)) { throw self::unavailableError(); }
        $file = $this->openFile($path, false);
        try {
            $stat = fstat($file);
            if ($stat['size'] > self::MAX_JOB_BYTES) { throw self::unavailableError(); }
            // PHP arrays occupy much more memory than their JSON representation.
            $this->guardMemory(max(2097152, $stat['size'] * 12), self::MEMORY);
            $json = stream_get_contents($file, self::MAX_JOB_BYTES + 1);
            if ($json === false || strlen($json) > self::MAX_JOB_BYTES) { throw self::unavailableError(); }
            $job = json_decode($json, true);
        }
        finally { fclose($file); }
        if (!is_array($job) || json_last_error() !== JSON_ERROR_NONE || ($job['id'] ?? '') !== $id
                || ($job['owner'] ?? '') !== $owner || !is_int($job['sequence'] ?? null) || $job['sequence'] < 0
                || !is_int($job['created_at'] ?? null) || !is_int($job['updated_at'] ?? null)
                || $this->expired($job)) {
            throw self::unavailableError();
        }
        try { $this->validateState($job['state'] ?? null); }
        catch (Throwable $e) { throw self::unavailableError(); }
        unset($job['status']); // Header-only duplicate, used for bounded quota/GC inspection.
        return $job;
    }

    private function save(array $job): void {
        $metadata = $job;
        unset($metadata['state']);
        $metadata['status'] = $job['state']['status'];
        $this->guardMemory(min(self::MAX_JOB_BYTES * 2, max(2097152, memory_get_usage(true))), self::PAYLOAD);
        // A short first line permits bounded GC inspection without decoding all timelines.
        $header = json_encode($metadata);
        $state = json_encode($job['state'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($header === false || $state === false || strlen($header) + strlen($state) + 10 > self::MAX_JOB_BYTES) {
            throw new AvailabilityJobException('Checkpoint cannot be serialized / Não foi possível serializar a etapa.', self::PAYLOAD);
        }
        // Do not save a checkpoint that has no conservative decoding headroom.
        $this->guardMemory(max(2097152, strlen($state) * 12), self::PAYLOAD);
        $json = substr($header, 0, -1) . ',"state":' . "\n" . $state . '}';
        unset($state);
        $target = $this->path($job['id'], $job['owner']);
        $temporary = substr($target, 0, -5) . '.tmp-' . bin2hex(random_bytes(16));
        $file = @fopen($temporary, 'x+b');
        if (!$file) { throw self::storageError(); }
        try {
            if (!@chmod($temporary, 0600)) { throw self::storageError(); }
            for ($written = 0, $length = strlen($json); $written < $length;) {
                $bytes = @fwrite($file, substr($json, $written, 1048576));
                if (!$bytes) { throw self::storageError(); }
                $written += $bytes;
            }
            if (!@fflush($file)) { throw self::storageError(); }
            fclose($file);
            $file = null;
            if (is_link($target) || !@rename($temporary, $target)) { throw self::storageError(); }
        }
        finally {
            if (is_resource($file)) { fclose($file); }
            if (is_file($temporary) && !is_link($temporary)) { @unlink($temporary); }
        }
    }

    private function collectGarbage(): void {
        foreach ($this->jobFiles(true) as $job) {
            try {
                $this->locked($this->lockName($job['id']), LOCK_EX, function() use ($job): void {
                    $path = $this->directory . DIRECTORY_SEPARATOR . $job['name'];
                    clearstatcache(true, $path);
                    $stat = @lstat($path);
                    if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
                            || !$this->privateOwner($stat) || ($stat['nlink'] ?? 1) !== 1) { return; }
                    $expired = $stat['mtime'] <= $this->now() - self::IDLE_TTL;
                    if (!$job['temporary']) {
                        $metadata = $this->metadata($path, $job['id'], $job['owner']);
                        if ($metadata !== null) { $expired = $this->expired($metadata); }
                    }
                    if ($expired) { @unlink($path); }
                });
            }
            catch (AvailabilityJobException $e) {
                // Busy jobs are never removed; a damaged file is not followed or trusted.
            }
        }
    }

    private function makeRoom(array $jobs, string $owner): void {
        $owned = array_values(array_filter($jobs, static function(array $job) use ($owner): bool {
            return $job['owner'] === $owner;
        }));
        $ownerCount = count($owned);
        $total = count($jobs);
        if ($ownerCount < self::MAX_OWNER_JOBS && $total < self::MAX_JOBS) { return; }
        $candidates = [];
        foreach ($owned as $job) {
            try {
                $metadata = $this->locked($this->lockName($job['id']), LOCK_SH, function() use ($job) {
                    return $this->metadata($this->path($job['id'], $job['owner']), $job['id'], $job['owner']);
                });
                if ($metadata !== null && in_array($metadata['status'] ?? '', ['complete', 'failed', 'cancelled'], true)) {
                    $candidates[] = $metadata;
                }
            }
            catch (AvailabilityJobException $e) { /* Busy or unsafe files are not eviction candidates. */ }
        }
        usort($candidates, static function(array $a, array $b): int { return $a['updated_at'] <=> $b['updated_at']; });
        foreach ($candidates as $candidate) {
            if ($ownerCount < self::MAX_OWNER_JOBS && $total < self::MAX_JOBS) { break; }
            try {
                $removed = $this->locked($this->lockName($candidate['id']), LOCK_EX, function() use ($candidate): bool {
                    $path = $this->path($candidate['id'], $candidate['owner']);
                    $metadata = $this->metadata($path, $candidate['id'], $candidate['owner']);
                    return $metadata !== null && in_array($metadata['status'] ?? '', ['complete', 'failed', 'cancelled'], true)
                        && !is_link($path) && @unlink($path);
                });
                if ($removed) { $ownerCount--; $total--; }
            }
            catch (AvailabilityJobException $e) { /* Never wait for a job another request is using. */ }
        }
    }

    private function metadata(string $path, string $id, string $owner): ?array {
        $file = $this->openFile($path, false);
        try { $line = fgets($file, 1024); }
        finally { fclose($file); }
        if (is_string($line) && substr($line, -10) === ',"state":' . "\n") {
            $metadata = json_decode(substr($line, 0, -10) . '}', true);
            if (is_array($metadata) && ($metadata['id'] ?? '') === $id && ($metadata['owner'] ?? '') === $owner
                    && is_int($metadata['created_at'] ?? null) && is_int($metadata['updated_at'] ?? null)) {
                return $metadata;
            }
        }
        return null;
    }

    private function jobFiles(bool $includeTemporary = false): array {
        $jobs = [];
        $entries = @scandir($this->directory);
        if ($entries === false) { throw self::storageError(); }
        foreach ($entries as $name) {
            if (preg_match('/^([1-9][0-9]{0,19})-([a-f0-9]{64})\.(json|tmp-[a-f0-9]{32})$/D', $name, $parts)) {
                $temporary = $parts[3] !== 'json';
                if (!$temporary || $includeTemporary) {
                    $jobs[] = ['owner' => $parts[1], 'id' => $parts[2], 'name' => $name, 'temporary' => $temporary];
                }
            }
        }
        return $jobs;
    }

    private function locked(string $name, int $mode, callable $operation) {
        $file = $this->openFile($this->directory . DIRECTORY_SEPARATOR . $name, true);
        if (!@flock($file, $mode | LOCK_NB)) {
            fclose($file);
            throw new AvailabilityJobException('Calculation busy; retry shortly / Cálculo ocupado; tente novamente em instantes.', self::BUSY);
        }
        try { return $operation(); }
        finally { flock($file, LOCK_UN); fclose($file); }
    }

    private function openFile(string $path, bool $create) {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!$before && $create) {
            $file = @fopen($path, 'x+b');
            if ($file) {
                if (!@chmod($path, 0600)) { fclose($file); throw self::storageError(); }
                fclose($file);
            }
            clearstatcache(true, $path);
            $before = @lstat($path);
        }
        if (!$before || is_link($path) || ($before['mode'] & 0170000) !== 0100000
                || !$this->privateOwner($before) || ($before['nlink'] ?? 1) !== 1) {
            throw self::storageError();
        }
        $file = @fopen($path, $create ? 'r+b' : 'rb');
        if (!$file) { throw self::storageError(); }
        $after = fstat($file);
        if (!$after || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']) {
            fclose($file);
            throw self::storageError();
        }
        return $file;
    }

    private function privateOwner(array $stat): bool {
        // Windows chmod does not implement POSIX ACLs; deploy in the PHP account's private temp directory.
        return DIRECTORY_SEPARATOR === '\\' || (($stat['mode'] & 0077) === 0
            && (!function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid()));
    }

    private function expired(array $job): bool {
        return $job['updated_at'] <= $this->now() - self::IDLE_TTL
            || $job['created_at'] <= $this->now() - self::ABSOLUTE_TTL
            || $job['created_at'] > $job['updated_at'];
    }

    private function guardMemory(int $additionalBytes, int $code): void {
        $limit = 268435456;
        $setting = trim((string) ini_get('memory_limit'));
        if (preg_match('/^(\d+)\s*([kmg]?)$/iD', $setting, $parts)) {
            $unit = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            $configured = (float) $parts[1] * $unit[strtolower($parts[2])];
            if ($configured > 0) { $limit = (int) min($limit, $configured); }
        }
        if ($additionalBytes > $limit - memory_get_usage(true) - 16777216) {
            throw new AvailabilityJobException(
                'Safe checkpoint memory budget reached; reduce the scope / Limite seguro de memória da etapa atingido; reduza o escopo.', $code
            );
        }
    }

    private function now(): int { return (int) call_user_func($this->clock); }
    private function path(string $id, string $owner): string {
        return $this->directory . DIRECTORY_SEPARATOR . $owner . '-' . $id . '.json';
    }
    private function lockName(string $id): string { return '.job-' . substr($id, 0, 2) . '.lock'; }
    private static function validateOwner(string $owner): void {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $owner)) { throw self::invalidError(); }
    }
    private static function validateId(string $id): void {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) { throw self::invalidError(); }
    }
    private static function invalidError(): AvailabilityJobException {
        return new AvailabilityJobException('Invalid calculation request / Solicitação de cálculo inválida.', self::INVALID);
    }
    private static function unavailableError(): AvailabilityJobException {
        return new AvailabilityJobException('Calculation unavailable or expired. Start a new calculation / Cálculo indisponível ou expirado. Inicie um novo cálculo.', self::UNAVAILABLE);
    }
    private static function storageError(): AvailabilityJobException {
        return new AvailabilityJobException('Private temporary calculation storage is unavailable / O armazenamento temporário privado do cálculo está indisponível.', self::STORAGE);
    }
}
