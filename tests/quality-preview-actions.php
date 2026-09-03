<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/actions.php';
require __DIR__ . '/quality-fixture.php';
require __DIR__ . '/../actions/QualityRun.php';
require __DIR__ . '/../QualityCatalog.php';
set_error_handler(static function($s,$m,$f,$l) { if (error_reporting() & $s) throw new ErrorException($m,0,$s,$f,$l); return false; });
class PreviewHarness extends Modules\Governance\Actions\QualityRun {
    public $store;
    public $catalogCalls = 0;
    protected function catalogGet(string $service, array $options) {
        $this->catalogCalls++;
        assertAction($service === 'HostGroup' && $options['limit'] === 21, 'Bounded group lookup');
        return [['groupid' => '99', 'name' => 'Equipes/DBD']];
    }
    protected function jobStore(): Modules\Governance\QualityJobStore { return $this->store; }
    public function run() { $this->response = null; if ($this->checkPermissions() && $this->checkInput()) $this->doAction(); }
    public function body() { return json_decode($this->response->data['main_block'],true); }
}
$folder = sys_get_temp_dir() . '/quality-preview-test-' . bin2hex(random_bytes(6));
$controller = new PreviewHarness();
$controller->store = new Modules\Governance\QualityJobStore($folder);
CWebUser::$data['userid'] = '123'; $_SERVER['REQUEST_METHOD'] = 'POST';
$card = fixtureConfig()['quality_pages'][0]['cards'][2];
$card['selection'] = ['version'=>1,'mode'=>'all','conditions'=>[['type'=>'tag','operator'=>'equals','name'=>'Departamento','value'=>'DBD']]];
$controller->input = ['operation'=>'preview_start','request_id'=>str_repeat('c',64),'card_json'=>json_encode($card)];
$writes = API::$module->writes;
$controller->run(); $body = $controller->body();
assertAction($body['status']==='running' && $body['page']==='preview','Preview accepts unsaved valid card');
assertAction($body['progress']['calls']===0 && $body['result']===null,'Start does not scan hosts');
assertAction(API::$module->writes===$writes,'Preview never saves configuration');
$controller->run(); assertAction($controller->body()['job']===$body['job'],'Start replay is idempotent');
$controller->input=['operation'=>'cancel','job'=>$body['job'],'sequence'=>0];
$controller->run(); assertAction($controller->body()['status']==='cancelled','Can cancel preview checkpoint');
$controller->input=['operation'=>'status','job'=>$body['job']]; CWebUser::$data['userid']='124';
$controller->run(); assertAction($controller->body()['status']==='failed','Other user cannot read preview');
$controller->type=1; $controller->run(); assertAction($controller->response===null,'Non-admin denied');
$controller->type=3; CWebUser::$data['userid']='123';
$controller->input=['operation'=>'preview_start','request_id'=>str_repeat('d',64),'card_json'=>'{}'];
$controller->run(); assertAction($controller->body()['status']==='failed','Invalid draft rejected');
$controller->input['card_json']=str_repeat('x',20001); $controller->run(); assertAction($controller->body()['status']==='failed','Oversized draft rejected');
$_SERVER['REQUEST_METHOD']='GET'; $controller->run(); assertAction($controller->body()['status']==='failed','GET cannot start preview');
$_SERVER['REQUEST_METHOD']='POST';
$controller->store = null;
$controller->input=['operation'=>'lookup','lookup_type'=>'group','query'=>'DBD'];
$controller->run(); assertAction($controller->body()['items'][0]['id']==='99','Lookup needs no job store and returns catalog');
assertAction(API::$module->writes===$writes,'Lookup does not save configuration');
$controller->input['query']='x'; $controller->run(); assertAction($controller->body()['status']==='failed','Short lookup rejected');
$controller->input['query']='DBD'; $controller->type=1; $controller->run(); assertAction($controller->response===null,'Lookup enforces permissions');
$controller->type=3; $_SERVER['REQUEST_METHOD']='GET'; $controller->run(); assertAction($controller->body()['status']==='failed','Lookup rejects GET');
assertAction($controller->catalogCalls===1,'Invalid lookup never calls API');
echo "PASS: preview controller permission, validation and read-only draft tests\n";
