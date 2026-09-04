<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/quality-fixture.php';
use Modules\Governance\QualityCalculation as Calculation;
use Modules\Governance\GovernanceConfig as Config;
use Modules\Governance\QualityJobException as JobException;
use Modules\Governance\QualityJobStore as Store;

$assertions = 0;
$skipped = 0;
function jobCheck($condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function jobReject(callable $operation, string $message, ?int $code = null): void {
    try { $operation(); }
    catch (Throwable $e) {
        jobCheck($code === null || ($e instanceof JobException && $e->getCode() === $code), $message . ' (error code)');
        return;
    }
    jobCheck(false, $message);
}
function jobNonce(string $label): string { return hash('sha256', $label); }
function jobState(string $status = 'running'): array {
    $config = fixtureConfig();
    $state = Calculation::create($config, 'main', [], Config::qualityRevision($config));
    $state['status'] = $status;
    $state['progress']['rows'] = 0;
    $state['secret_working_state'] = [1, 2, 3];
    return $state;
}
function jobPath(string $directory, array $job): string { return $directory . '/' . $job['owner'] . '-' . $job['id'] . '.json'; }
function jobLock(string $directory, array $job) {
    $file = fopen($directory . '/.job-' . substr($job['id'], 0, 2) . '.lock', 'r+b');
    if (!$file || !flock($file, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Cannot lock test fixture.'); }
    return $file;
}
function jobUnlock($file): void { flock($file, LOCK_UN); fclose($file); }

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'governance-quality-job-tests-' . bin2hex(random_bytes(12));
if (!mkdir($testRoot, 0700)) { throw new RuntimeException('Cannot create isolated fixture directory.'); }
$testRoot = realpath($testRoot);
$testDirectories = [];
$newDirectory = static function(string $label) use ($testRoot, &$testDirectories): string {
    $directory = $testRoot . DIRECTORY_SEPARATOR . $label;
    $testDirectories[] = $directory;
    return $directory;
};

try {
    $now = time();
    $directory = $newDirectory('lifecycle');
    $store = new Store($directory, static function() use (&$now): int { return $now; });
    $initializations = 0;
    $job = $store->create('1', jobNonce('first'), static function() use (&$initializations): array {
        $initializations++;
        return jobState();
    });
    jobCheck($job['id'] === hash('sha256', '1:' . jobNonce('first')), 'job ID is deterministic and user-bound');
    jobCheck($job['sequence'] === 0 && $job['state']['status'] === 'running', 'starts at a running checkpoint zero');
    $duplicate = $store->create('1', jobNonce('first'), static function() use (&$initializations): array {
        $initializations++;
        return jobState('complete');
    });
    jobCheck($initializations === 1 && $duplicate === $job, 'lost start responses do not initialize twice or alter a frozen job');
    jobCheck($store->read($job['id'], '1') === $job, 'checkpoint JSON round trip preserves state and sequence');
    jobReject(static function() use ($store, $job) { $store->read($job['id'], '2'); }, 'another owner cannot read a job', Store::UNAVAILABLE);
    jobReject(static function() use ($store, $job) { $store->cancel($job['id'], '2', 0); }, 'another owner cannot cancel a job', Store::UNAVAILABLE);
    foreach (['../outside', str_repeat('a', 63), str_repeat('A', 64), str_repeat('a', 64) . "\n"] as $invalid) {
        jobReject(static function() use ($store, $invalid) { $store->read($invalid, '1'); }, 'unsafe job IDs are rejected', Store::INVALID);
    }
    foreach (['', '0', '../1', '01', '1/2', str_repeat('1', 21)] as $owner) {
        jobReject(static function() use ($store, $job, $owner) { $store->read($job['id'], $owner); }, 'noncanonical owner rejected', Store::INVALID);
    }
    $steps = 0;
    $advance = static function(array $state) use (&$steps): array {
        $steps++;
        $state['progress']['rows'] += 50;
        return $state;
    };
    $job = $store->step($job['id'], '1', 0, $advance);
    jobCheck($steps === 1 && $job['sequence'] === 1 && $job['state']['progress']['rows'] === 50, 'one step commits exactly once');
    jobCheck($store->step($job['id'], '1', 0, $advance) === $job && $steps === 1, 'stale retries return the committed checkpoint');
    jobReject(static function() use ($store, $job, $advance) { $store->step($job['id'], '1', 2, $advance); }, 'future sequences are rejected', Store::INVALID);
    jobReject(static function() use ($store, $job, $advance) { $store->step($job['id'], '1', -1, $advance); }, 'negative sequences are rejected', Store::INVALID);
    $held = jobLock($directory, $job);
    try {
        jobReject(static function() use ($store, $job, $advance) { $store->step($job['id'], '1', 1, $advance); }, 'a concurrent step is nonblocking busy', Store::BUSY);
        jobReject(static function() use ($store, $job) { $store->read($job['id'], '1'); }, 'a concurrent read is nonblocking busy', Store::BUSY);
        jobReject(static function() use ($store, $job) { $store->cancel($job['id'], '1', 1); }, 'a concurrent cancellation is busy', Store::BUSY);
    }
    finally { jobUnlock($held); }
    jobCheck($store->read($job['id'], '1')['sequence'] === 1 && $steps === 1, 'busy operations leave the committed job untouched');
    jobCheck($store->cancel($job['id'], '1', 0) === $job, 'stale cancel does not overwrite a newer checkpoint');
    $cancelled = $store->cancel($job['id'], '1', 1);
    jobCheck($cancelled['sequence'] === 2 && $cancelled['state']['status'] === 'cancelled', 'cancellation is a sequenced terminal checkpoint');
    jobCheck(!isset($cancelled['state']['secret_working_state']), 'cancelled jobs discard private working arrays');
    jobCheck($store->step($job['id'], '1', 2, $advance) === $cancelled && $steps === 1, 'cancelled calculations never advance');
    $projected = Store::projection($cancelled);
    jobCheck(!isset($projected['state'], $projected['owner'], $projected['source_config'], $projected['result_url']), 'projection exposes no working state or partial result URL');
    jobCheck($projected['page'] === 'main' && $projected['revision'] === Config::qualityRevision(fixtureConfig()), 'projection retains frozen quality page revision');
    if (DIRECTORY_SEPARATOR !== '\\') {
        jobCheck((fileperms($directory) & 0077) === 0, 'private directory has no group/world permissions');
        jobCheck((fileperms(jobPath($directory, $job)) & 0077) === 0, 'checkpoint has no group/world permissions');
    }

    $failed = $store->create('1', jobNonce('failure'), static function(): array { return jobState(); });
    $failed = $store->step($failed['id'], '1', 0, static function(array $state): array {
        throw new RuntimeException('Credentials and C:\\private\\database.ini must not reach the client');
    });
    jobCheck($failed['state']['status'] === 'failed' && $failed['sequence'] === 1, 'uncaught calculation errors persist a terminal failure');
    jobCheck(strpos(Store::projection($failed)['error'], 'private') === false, 'raw exception text is not exposed');
    jobCheck(!isset(Store::projection($failed)['result_url']), 'failed job has no report URL');
    jobCheck($store->step($failed['id'], '1', 1, $advance) === $failed && $steps === 1, 'failed steps are not repeated');
    $failedStarts = 0;
    $badInitializer = static function() use (&$failedStarts): array { $failedStarts++; throw new RuntimeException('/private/start'); };
    $badStart = $store->create('1', jobNonce('bad-start'), $badInitializer);
    jobCheck($badStart['state']['status'] === 'failed', 'failed initialization is retained');
    jobCheck($store->create('1', jobNonce('bad-start'), $badInitializer) === $badStart && $failedStarts === 1, 'failed initialization is idempotent');
    $oversized = $store->create('2', jobNonce('oversized'), static function(): array {
        $state = jobState();
        $state['secret_working_state'] = str_repeat('x', Store::MAX_JOB_BYTES + 1);
        return $state;
    });
    jobCheck($oversized['state']['status'] === 'failed', 'oversized payload becomes a small failed checkpoint');
    jobCheck(filesize(jobPath($directory, $oversized)) < 10000, 'oversized working payload is discarded');

    $ttlDirectory = $newDirectory('expiry');
    $ttl = new Store($ttlDirectory, static function() use (&$now): int { return $now; });
    $old = $ttl->create('1', jobNonce('old'), static function(): array { return jobState(); });
    $created = $now;
    $now += Store::IDLE_TTL - 1;
    jobCheck($ttl->read($old['id'], '1')['updated_at'] === $created, 'read/status do not keep an abandoned calculation alive');
    $now++;
    jobReject(static function() use ($ttl, $old) { $ttl->read($old['id'], '1'); }, 'idle TTL is enforced', Store::UNAVAILABLE);
    $freshNonce = jobNonce('fresh');
    while (substr(hash('sha256', '1:' . $freshNonce), 0, 2) === substr($old['id'], 0, 2)) { $freshNonce = hash('sha256', $freshNonce); }
    $held = jobLock($ttlDirectory, $old);
    try {
        $fresh = $ttl->create('1', $freshNonce, static function(): array { return jobState(); });
        jobCheck(is_file(jobPath($ttlDirectory, $old)), 'garbage collection does not unlink a locked job, even if expired');
    }
    finally { jobUnlock($held); }
    $ttl->create('2', jobNonce('gc-trigger'), static function(): array { return jobState(); });
    jobCheck(!file_exists(jobPath($ttlDirectory, $old)), 'expired unlocked checkpoints are collected');
    $now += Store::IDLE_TTL - 1;
    $fresh = $ttl->step($fresh['id'], '1', 0, $advance);
    $now += Store::IDLE_TTL - 1;
    $fresh = $ttl->step($fresh['id'], '1', 1, $advance);
    $now += Store::ABSOLUTE_TTL - 2 * Store::IDLE_TTL + 2;
    jobReject(static function() use ($ttl, $fresh) { $ttl->read($fresh['id'], '1'); }, 'absolute TTL is enforced despite recent checkpoints', Store::UNAVAILABLE);
    $ttl->create('3', jobNonce('absolute-gc'), static function(): array { return jobState(); });
    jobCheck(!file_exists(jobPath($ttlDirectory, $fresh)), 'bounded header inspection finds absolute expiry');

    $quotaDirectory = $newDirectory('quota');
    $quota = new Store($quotaDirectory, static function() use (&$now): int { return $now; });
    $retained = [];
    for ($i = 0; $i < Store::MAX_OWNER_JOBS; $i++) {
        $retained[] = $quota->create('1', jobNonce('retained-' . $i), static function(): array { return jobState(); });
        $now++;
    }
    jobReject(static function() use ($quota) { $quota->create('1', jobNonce('over-quota'), static function(): array { return jobState(); }); }, 'per-owner quota never evicts running jobs', Store::CAPACITY);
    $quota->cancel($retained[0]['id'], '1', 0);
    $replacement = $quota->create('1', jobNonce('over-quota'), static function(): array { return jobState(); });
    jobCheck($replacement['state']['status'] === 'running' && !file_exists(jobPath($quotaDirectory, $retained[0])), 'terminal jobs free a slot for the owner');
    jobCheck($quota->read($retained[1]['id'], '1')['state']['status'] === 'running', 'terminal eviction preserves other running jobs');
    $globalDirectory = $newDirectory('global-quota');
    $global = new Store($globalDirectory);
    for ($i = 1; $i <= Store::MAX_JOBS; $i++) {
        $global->create((string) $i, jobNonce('global'), static function(): array { return jobState(); });
    }
    jobReject(static function() use ($global) { $global->create('99', jobNonce('global'), static function(): array { return jobState(); }); }, 'global quota is enforced across owners', Store::CAPACITY);

    $unsafeDirectory = $newDirectory('unsafe-files');
    $unsafe = new Store($unsafeDirectory);
    $corrupt = $unsafe->create('1', jobNonce('corrupt'), static function(): array { return jobState(); });
    file_put_contents(jobPath($unsafeDirectory, $corrupt), '{broken');
    jobReject(static function() use ($unsafe, $corrupt) { $unsafe->read($corrupt['id'], '1'); }, 'corrupt JSON is rejected', Store::UNAVAILABLE);
    $sentinel = $testRoot . DIRECTORY_SEPARATOR . 'sentinel.txt';
    file_put_contents($sentinel, 'unchanged');
    $linked = $unsafe->create('1', jobNonce('symlink'), static function(): array { return jobState(); });
    unlink(jobPath($unsafeDirectory, $linked));
    if (@symlink($sentinel, jobPath($unsafeDirectory, $linked))) {
        jobReject(static function() use ($unsafe, $linked) { $unsafe->read($linked['id'], '1'); }, 'symlink checkpoints are never read', Store::STORAGE);
        jobCheck(file_get_contents($sentinel) === 'unchanged', 'symlink target is not modified');
    }
    else { $skipped++; }
    $linkedDirectory = $newDirectory('linked-directory');
    if (@symlink($unsafeDirectory, $linkedDirectory)) {
        jobReject(static function() use ($linkedDirectory) { new Store($linkedDirectory); }, 'a symlink storage directory is refused', Store::STORAGE);
    }
    else { $skipped++; }
    $memoryJob = $unsafe->create('2', jobNonce('memory'), static function(): array { return jobState(); });
    $memoryJob['state']['large'] = str_repeat('x', 3 * 1048576);
    file_put_contents(jobPath($unsafeDirectory, $memoryJob), json_encode($memoryJob));
    unset($memoryJob['state']['large']);
    $originalLimit = ini_get('memory_limit');
    ini_set('memory_limit', (string) (memory_get_usage(true) + 20 * 1048576));
    try { jobReject(static function() use ($unsafe, $memoryJob) { $unsafe->read($memoryJob['id'], '2'); }, 'JSON allocation is checked before decoding', Store::MEMORY); }
    finally { ini_set('memory_limit', $originalLimit); }


    define('USER_TYPE_SUPER_ADMIN', 3);
    class CController {
        protected $input, $sidRequired = true;
        public $response;
        public function __construct(array $input) { $this->input = $input; }
        protected function getUserType() { return CWebUser::$data['type']; }
        protected function getInput($key, $default = null) {
            return $default === null ? $this->input[$key] : ($this->input[$key] ?? $default);
        }
        protected function hasInput($key) { return array_key_exists($key, $this->input); }
        protected function setResponse($response) { $this->response = $response; }
        protected function validateInput(array $rules): bool {
            foreach ($rules as $field => $rule) {
                if (!array_key_exists($field, $this->input)) { if (strpos($rule, 'required') !== false) return false; continue; }
                if (strpos($rule, 'string') !== false && !is_string($this->input[$field])) return false;
                if (strpos($rule, 'array_db') !== false && !is_array($this->input[$field])) return false;
                if (strpos($rule, 'int32') !== false && filter_var($this->input[$field], FILTER_VALIDATE_INT) === false) return false;
            }
            return true;
        }
        public function run() {
            if (($this->input['sid'] ?? '') !== 'test-valid-sid') throw new RuntimeException('Native SID rejected');
            if ($this->checkInput()) {
                if (!$this->checkPermissions()) throw new RuntimeException('Native permission rejected');
                $this->doAction();
            }
            return json_decode($this->response->data['main_block'], true);
        }
    }
    class CControllerResponseData { public $title; public function getData() { return $this->data; } public function setTitle($title) { $this->title = $title; } public $data; public function __construct(array $data) { $this->data = $data; } }
    class CWebUser { public static $data = ['userid' => '41', 'type' => 3]; }
    class API {
        public static $config, $fixture, $moduleReads = 0;
        public static function __callStatic($service, $args) {
            return new class($service) {
                private $service;
                public function __construct($service) { $this->service = $service; }
                public function get($options) {
                    if ($this->service === 'Module') { API::$moduleReads++; return [['config' => API::$config]]; }
                    return API::$fixture->get($this->service, $options);
                }
            };
        }
    }
    require __DIR__ . '/../actions/QualityRun.php';
    class QualityRunHarness extends Modules\Governance\Actions\QualityRun {
        public $store;
        protected function jobStore(): Store { return $this->store; }
    }
    $controllerDirectory = $newDirectory('controller');
    $controllerStore = new Store($controllerDirectory);
    $request = static function(array $input, string $method = 'POST', bool $sid = true) use ($controllerStore) {
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($sid) $input['sid'] = 'test-valid-sid';
        $action = new QualityRunHarness($input); $action->store = $controllerStore;
        return $action->run();
    };
    API::$config = fixtureConfig(); API::$fixture = new QualityFixture();
    $start = ['operation' => 'start', 'page' => 'main', 'revision' => Config::qualityRevision(API::$config),
        'request_id' => jobNonce('controller'), 'groupids' => ['10']];
    jobCheck($request($start, 'GET')['status'] === 'failed', 'GET cannot initiate work');
    jobReject(static function() use ($request, $start) { $request($start, 'POST', false); }, 'native SID is required');
    CWebUser::$data['type'] = 1;
    jobReject(static function() use ($request, $start) { $request($start); }, 'non-admin rejected on endpoint');
    CWebUser::$data['type'] = 3;
    foreach ([[], ['operation' => 'bogus'], ['operation' => 'step', 'job' => str_repeat('a', 64)],
        ['operation' => 'step', 'job' => str_repeat('a', 64), 'sequence' => -1],
        array_replace($start, ['revision' => 'bad']), array_replace($start, ['groupids' => 'bad'])] as $bad) {
        jobCheck($request($bad)['status'] === 'failed', 'invalid request rejected');
    }
    $started = $request($start);
    jobCheck($started['status'] === 'running' && $started['result'] === null && API::$fixture->calls === [], 'POST start only freezes config');
    jobCheck($request($start) === $started && API::$moduleReads === 1, 'repeated start does not re-read rules');
    $query = ['operation' => 'step', 'job' => $started['job'], 'sequence' => 0];
    $step = $request($query); $calls = count(API::$fixture->calls);
    jobCheck($request($query) === $step && count(API::$fixture->calls) === $calls, 'step retry is idempotent');
    $query['operation'] = 'status';
    jobCheck($request($query) === $step && count(API::$fixture->calls) === $calls, 'status never runs metrics');
    $held = jobLock($controllerDirectory, $controllerStore->read($started['job'], '41'));
    try { jobCheck($request($query)['status'] === 'busy', 'busy is nonblocking'); } finally { jobUnlock($held); }
    $complete = $step;
    while ($complete['status'] === 'running') {
        $complete = $request(['operation' => 'step', 'job' => $complete['job'], 'sequence' => $complete['sequence']]);
    }
    jobCheck($complete['result']['overall_score'] === 50.2 && $complete['status'] === 'complete', 'actual controller/API pipeline completes');
    CWebUser::$data['userid'] = '42';
    $foreign = $request(['operation' => 'status', 'job' => $complete['job']]);
    jobCheck($foreign['status'] === 'failed' && !isset($foreign['result']), 'another superadmin cannot see result');
    $manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true);
    jobCheck($manifest['actions']['governance.quality.run']['layout'] === 'layout.json', 'native JSON response layout registered');
    jobCheck(strpos(file_get_contents(__DIR__ . '/../actions/QualityRun.php'), 'disableSIDvalidation') === false, 'SID never disabled');
}

finally {
    // Only these exact, generated fixture directories are removed. Do not follow symlinks.
    foreach (array_reverse($testDirectories) as $directory) {
        if (dirname($directory) !== $testRoot) { throw new RuntimeException('Unsafe fixture cleanup target.'); }
        if (is_link($directory)) { unlink($directory); continue; }
        if (!is_dir($directory)) { continue; }
        if (realpath(dirname($directory)) !== $testRoot) { throw new RuntimeException('Fixture directory escaped its test root.'); }
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) || is_link($path)) { unlink($path); }
        }
        rmdir($directory);
    }
    foreach (scandir($testRoot) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $testRoot . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path) || is_link($path)) { unlink($path); }
    }
    rmdir($testRoot);
}
echo "PASS: $assertions quality job assertions ($skipped symlink checks unavailable)\n";
