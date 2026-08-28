<?php
// Isolated CLI tests only. No Zabbix database or production endpoint is accessed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../AvailabilityConfig.php';
require __DIR__ . '/../AvailabilityEngine.php';
require __DIR__ . '/../AvailabilityFreshness.php';
require __DIR__ . '/../AvailabilityCalculation.php';
require __DIR__ . '/../AvailabilityJobStore.php';

use Modules\Governance\AvailabilityCalculation as Calculation;
use Modules\Governance\AvailabilityConfig as Config;
use Modules\Governance\AvailabilityJobException as JobException;
use Modules\Governance\AvailabilityJobStore as Store;

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
    return ['status' => $status, 'format' => 1, 'source_config' => Config::defaults(), 'department_filter' => -1,
        'report' => ['month' => '2026-05', 'timezone' => 'America/Cuiaba', 'from' => 1777608000,
            'to' => 1780286400, 'generated_at' => 1780286400, 'departments' => []],
        'progress' => ['hosts_total' => 2, 'hosts_done' => 0, 'checks_total' => 2, 'checks_done' => 0,
            'rows' => 0, 'calls' => 0, 'percent' => 0, 'stage' => 'history', 'host' => 'Server'],
        'secret_working_state' => ['samples' => [1, 2, 3]]];
}
function jobPath(string $directory, array $job): string { return $directory . '/' . $job['owner'] . '-' . $job['id'] . '.json'; }
function jobLock(string $directory, array $job) {
    $file = fopen($directory . '/.job-' . substr($job['id'], 0, 2) . '.lock', 'r+b');
    if (!$file || !flock($file, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Cannot lock test fixture.'); }
    return $file;
}
function jobUnlock($file): void { flock($file, LOCK_UN); fclose($file); }

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'governance-job-tests-' . bin2hex(random_bytes(12));
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
    jobCheck($projected['snapshot']['month'] === '2026-05' && $projected['snapshot']['to'] === 1780286400, 'projection retains frozen period labels');
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
    $now += 2;
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

    // Model the native SID gate and controller order, then exercise the real module actions.
    define('USER_TYPE_SUPER_ADMIN', 3);
    class CController {
        protected $input;
        protected $sidRequired = true;
        public $response;
        public function __construct(array $input = []) { $this->input = $input; $this->init(); }
        protected function init() {}
        protected function disableSIDvalidation() { $this->sidRequired = false; }
        protected function getUserType() { return CWebUser::$data['type']; }
        protected function getInput($key, $default = null) { return $this->input[$key] ?? $default; }
        protected function hasInput($key) { return array_key_exists($key, $this->input); }
        protected function setResponse($response) { $this->response = $response; }
        protected function validateInput(array $rules): bool {
            foreach ($rules as $field => $rule) {
                if (!array_key_exists($field, $this->input)) { if (strpos($rule, 'required') !== false) { return false; } continue; }
                if (strpos($rule, 'string') !== false && !is_string($this->input[$field])) { return false; }
                if (strpos($rule, 'int32') !== false && (filter_var($this->input[$field], FILTER_VALIDATE_INT) === false
                        || $this->input[$field] < -2147483648 || $this->input[$field] > 2147483647)) { return false; }
            }
            return true;
        }
        public function run() {
            if ($this->sidRequired && ($this->input['sid'] ?? '') !== 'test-valid-sid') { throw new RuntimeException('Native SID rejected.'); }
            if ($this->checkInput()) {
                if (!$this->checkPermissions()) { throw new RuntimeException('Native permissions rejected.'); }
                $this->doAction();
            }
            return $this->response;
        }
    }
    class CControllerResponseData { public $data; public function __construct(array $data) { $this->data = $data; } }
    class CControllerResponseFatal {}
    class CWebUser {
        public static $data = ['userid' => '41', 'type' => 3, 'theme' => 'blue-theme'];
        public static function getLang() { return 'pt_BR'; }
    }
    function getUserTheme($data) { return $data['theme']; }
    class API {
        public static $config;
        public static $calls = [];
        public static function Module() { return new JobApi('Module'); }
        public static function HostGroup() { return new JobApi('HostGroup'); }
        public static function Host() { return new JobApi('Host'); }
        public static function Item() { return new JobApi('Item'); }
        public static function History() { return new JobApi('History'); }
    }
    class JobApi {
        private $kind;
        public function __construct(string $kind) { $this->kind = $kind; }
        public function get(array $options) {
            API::$calls[] = [$this->kind, $options];
            switch ($this->kind) {
                case 'Module': return [['moduleid' => '1', 'config' => API::$config]];
                case 'HostGroup': return [['groupid' => '1', 'name' => 'Services']];
                case 'Host': return [['hostid' => '10', 'name' => 'Frozen host', 'status' => 0]];
                case 'Item': return [['itemid' => '100', 'hostid' => '10', 'key_' => 'ping', 'value_type' => 3,
                    'status' => 0, 'delay' => '60', 'type' => 0, 'preprocessing' => []]];
                case 'History':
                    $from = (new DateTimeImmutable('2026-05-01', new DateTimeZone('America/Cuiaba')))->getTimestamp();
                    $samples = [];
                    for ($clock = $from - 86400; $clock < $from + 32 * 86400; $clock += 86400) {
                        if ($clock >= $options['time_from'] && $clock <= $options['time_till']) {
                            $samples[] = ['clock' => (string) $clock, 'ns' => '0', 'value' => '1'];
                        }
                    }
                    return array_slice($samples, 0, $options['limit']);
            }
            throw new RuntimeException('Unexpected mocked API call.');
        }
    }
    require __DIR__ . '/../actions/AvailabilityRun.php';
    require __DIR__ . '/../actions/AvailabilityView.php';
    class JobRunHarness extends Modules\Governance\Actions\AvailabilityRun {
        public $store;
        protected function jobStore(): Store { return $this->store; }
    }
    class JobViewHarness extends Modules\Governance\Actions\AvailabilityView {
        public $store;
        protected function jobStore(): Store { return $this->store; }
    }
    function jobRequest(Store $store, array $input, bool $withSid = true): array {
        if ($withSid) { $input += ['sid' => 'test-valid-sid']; }
        $action = new JobRunHarness($input);
        $action->store = $store;
        $response = $action->run();
        return json_decode($response->data['main_block'], true);
    }
    $controllerDirectory = $newDirectory('controllers');
    $controllerStore = new Store($controllerDirectory);
    API::$config = ['availability' => Config::validate(['timezone' => 'America/Cuiaba', 'departments' => [
        ['name' => 'Frozen department', 'target' => 99.9, 'technologies' => [
            ['name' => 'Ping', 'weight' => 1, 'target' => 99.9, 'groups' => 'Services', 'mode' => 'any_down',
                'checks' => [['key' => 'ping', 'max_age' => 86400, 'up' => ['op' => 'eq', 'a' => 1], 'down' => null]]]
        ]]
    ]])];
    $initialView = new JobViewHarness(['month' => '2026-05']);
    $initialView->store = $controllerStore;
    $initialData = $initialView->run()->data;
    jobCheck($initialData['report'] === null && $initialData['job'] === null, 'GET initial view does not calculate');
    jobCheck(array_column(API::$calls, 0) === ['Module'], 'GET initial view only loads saved rules, never history');
    $startRequest = ['operation' => 'start', 'month' => '2026-05', 'department' => '0', 'request_id' => jobNonce('controller')];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rejectedGet = jobRequest($controllerStore, $startRequest);
    jobCheck($rejectedGet['status'] === 'failed' && count(API::$calls) === 1, 'GET cannot start a calculation, even with a SID');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    jobReject(static function() use ($controllerStore, $startRequest) { jobRequest($controllerStore, $startRequest, false); }, 'missing native SID is rejected');
    jobReject(static function() use ($controllerStore, $startRequest) { jobRequest($controllerStore, $startRequest + ['sid' => 'wrong']); }, 'wrong native SID is rejected');
    CWebUser::$data['type'] = 1;
    jobReject(static function() use ($controllerStore, $startRequest) { jobRequest($controllerStore, $startRequest); }, 'non-superadmins cannot start jobs');
    CWebUser::$data['type'] = 3;
    $started = jobRequest($controllerStore, $startRequest + ['state' => ['status' => 'complete'], 'config' => ['timezone' => 'UTC']]);
    jobCheck($started['status'] === 'running' && $started['sequence'] === 0, 'POST starts a real resumable calculation');
    jobCheck($started['snapshot']['department_name'] === 'Frozen department' && $started['snapshot']['timezone'] === 'America/Cuiaba', 'client-supplied state/config cannot replace server rules');
    jobCheck(!isset($started['state'], $started['report'], $started['result_url']), 'start response is a projection, never an unfinished report');
    $callsBeforeRetry = count(API::$calls);
    API::$config['availability']['departments'][0]['name'] = 'Changed rules';
    jobCheck(jobRequest($controllerStore, array_replace($startRequest, ['month' => '2026-04'])) === $started, 'retry start retains original month and rules');
    jobCheck(count(API::$calls) === $callsBeforeRetry, 'duplicate start does not re-read changed configuration');
    $activeView = new JobViewHarness(['job' => $started['job'], 'month' => '2020-01', 'department' => '-1']);
    $activeView->store = $controllerStore;
    $activeData = $activeView->run()->data;
    jobCheck($activeData['report'] === null && $activeData['job']['status'] === 'running', 'GET reopening an active job exposes only progress');
    jobCheck($activeData['month'] === '2026-05' && $activeData['department'] === 0, 'job view ignores changed URL month/filter');
    jobCheck($activeData['config']['departments'][0]['name'] === 'Frozen department', 'active job view retains full frozen configuration');
    jobCheck(count(API::$calls) === $callsBeforeRetry, 'GET active job never advances the calculation or reads current rules');
    $status = jobRequest($controllerStore, ['operation' => 'status', 'job' => $started['job']]);
    jobCheck($status === $started, 'status returns the latest checkpoint without advancing');
    $activeEnvelope = $controllerStore->read($started['job'], '41');
    $held = jobLock($controllerDirectory, $activeEnvelope);
    try {
        $busy = jobRequest($controllerStore, ['operation' => 'step', 'job' => $started['job'], 'sequence' => 0]);
        jobCheck($busy['status'] === 'busy' && $busy['retryable'] === true, 'controller returns a retryable busy projection');
    }
    finally { jobUnlock($held); }
    $finished = $started;
    for ($i = 0; $i < 100 && $finished['status'] === 'running'; $i++) {
        $finished = jobRequest($controllerStore, ['operation' => 'step', 'job' => $finished['job'], 'sequence' => $finished['sequence']]);
    }
    jobCheck($finished['status'] === 'complete', 'real calculation reaches completion across controller POST steps');
    jobCheck($finished['result_url'] === 'zabbix.php?action=governance.availability.view&job=' . $finished['job'], 'only complete response carries an owned report URL');
    $callsBeforeView = count(API::$calls);
    $completedView = new JobViewHarness(['job' => $finished['job'], 'month' => '2020-01']);
    $completedView->store = $controllerStore;
    $completedData = $completedView->run()->data;
    jobCheck($completedData['report']['month'] === '2026-05' && $completedData['report']['departments'][0]['name'] === 'Frozen department', 'completed view uses frozen report fields');
    jobCheck(abs($completedData['report']['departments'][0]['summary']['score'] - 100) < 0.000001, 'completed controller pipeline retains correct availability');
    jobCheck(count(API::$calls) === $callsBeforeView, 'completed GET never reruns historical queries');
    CWebUser::$data['userid'] = '42';
    $foreign = jobRequest($controllerStore, ['operation' => 'status', 'job' => $finished['job']]);
    jobCheck($foreign['status'] === 'failed' && !isset($foreign['result_url'], $foreign['snapshot']), 'another superadmin cannot access job progress or report');
    $foreignView = new JobViewHarness(['job' => $finished['job']]);
    $foreignView->store = $controllerStore;
    $foreignData = $foreignView->run()->data;
    jobCheck($foreignData['report'] === null && $foreignData['job'] === null && $foreignData['error'] !== null, 'foreign report URL is refused');
    $manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true);
    jobCheck(($manifest['actions']['governance.availability.run']['layout'] ?? '') === 'layout.json', 'run uses native JSON layout rather than an empty null layout');
    jobCheck(strpos(file_get_contents(__DIR__ . '/../actions/AvailabilityRun.php'), 'disableSIDvalidation') === false, 'mutating controller does not disable native SID validation');
    jobCheck(strpos(file_get_contents(__DIR__ . '/../actions/AvailabilityView.php'), '->build(') === false, 'GET controller has no synchronous report build path');
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
echo 'PASS: ' . $assertions . ' availability job assertions' . ($skipped ? ' (' . $skipped . ' symlink checks unavailable)' : '') . "\n";
