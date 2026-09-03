<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/quality-fixture.php';
use Modules\Governance\GovernanceConfig as Config;
use Modules\Governance\QualityConditions as Rules;
use Modules\Governance\QualityCalculation as Calc;
set_error_handler(static function($s,$m,$f,$l) { throw new ErrorException($m,0,$s,$f,$l); });
$checks = 0;
function okRule($ok, $text) { global $checks; $checks++; if (!$ok) throw new RuntimeException($text); }
$tag = ['type'=>'tag','operator'=>'equals','name'=>'Departamento','value'=>'DBD'];
$group = ['type'=>'group','operator'=>'equals','value'=>'DBD','subgroups'=>1];
$template = ['type'=>'template','operator'=>'equals','value'=>'Linux'];
$inventory = ['type'=>'inventory','operator'=>'exists','name'=>'os'];
$host = ['tags'=>[['tag'=>'Departamento','value'=>'DBD']], 'groups'=>[['groupid'=>'10','name'=>'DBD/PostgreSQL']],
    'parentTemplates'=>[['templateid'=>'30','host'=>'linux-agent','name'=>'Linux']], 'inventory'=>['os'=>'Linux']];
$select = static function($rows,$mode='all') { return Rules::validate(['version'=>1,'mode'=>$mode,'conditions'=>$rows]); };
okRule(Rules::matches($host,$select([$tag,$group,$template,$inventory])), 'All four relation types match');
okRule(!Rules::matches($host,$select([$tag, array_replace($group,['subgroups'=>0])])), 'Exact group differs from subgroup');
okRule(Rules::matches($host,$select([$tag,array_replace($group,['value'=>'Other'])],'any')), 'OR across condition types');
okRule(!Rules::matches($host,$select([array_replace($group,['value'=>'DB'])])), 'No prefix collision');
okRule(Rules::matches($host,$select([array_replace($template,['value'=>'30'])])), 'Template by ID');
foreach ([$tag,$group,$template] as $rule) okRule(!Rules::matches($host,$select([array_replace($rule,['operator'=>'not_equals'])])), 'Negative conditions invert whole match');
okRule(Rules::matches([], $select([array_replace($tag,['operator'=>'not_exists'])])), 'Absent tag');
okRule(Rules::matches([], $select([array_replace($inventory,['operator'=>'not_exists'])])), 'Missing inventory counts empty');
okRule(Rules::matches([], $select([],'any')), 'Empty selection deliberately selects all');
$legacy = ['scope_tag_name'=>'Departamento','scope_tag_value'=>'DBD','scope_group_names'=>'DBD,Other','scope_include_subgroups'=>1];
okRule(Rules::matches($host,Rules::fromCard($legacy)), 'Legacy comma alternatives inside AND preserved');
foreach ([['version'=>2,'mode'=>'all','conditions'=>[]], ['version'=>1,'mode'=>'formula','conditions'=>[]],
    ['version'=>1,'mode'=>'all','conditions'=>array_fill(0,21,$tag)], ['version'=>1,'mode'=>'all','conditions'=>[['type'=>'inventory','operator'=>'exists','name'=>'bad']]]] as $invalid) {
    try { Rules::validate($invalid); okRule(false,'Accepted invalid selection'); } catch (InvalidArgumentException $e) { okRule(true,'Rejected'); }
}
$fixture = new QualityFixture(61);
foreach ($fixture->rows as &$row) { if ($row['status'] === 0) $row = array_replace($row,$host); } unset($row);
$card = fixtureConfig()['quality_pages'][0]['cards'][2]; $card['selection']=$select([$tag,$group]); $card['template_names']='Linux';
$config=['quality_pages'=>[['id'=>'main','name'=>'','cards'=>[$card]]]];
$normal=Calc::create($config,'main',[],Config::qualityRevision($config)); $preview=$normal; $preview['preview']=true; $preview['preview_hosts']=[];
$run=static function($state,$fixture) { $engine=new Calc([$fixture,'get']); for($i=0;$i<20 && $state['status']==='running';$i++)$state=$engine->advance($state); return $state; };
$normal=$run($normal,$fixture); $fixture->calls=[]; $preview=$run($preview,$fixture);
okRule($normal['result']['kpis']===$preview['result']['kpis'],'Preview uses identical denominator and metric');
okRule(count($preview['result']['preview_hosts'])===50 && $preview['result']['kpis'][0]['total_count']===61,'Bounded sample and exact total');
okRule(count($fixture->calls)===2,'Preview skips operational counters');
okRule($preview['result']['preview_hosts'][0]['compliant']===true,'Sample reports metric result');
okRule($fixture->calls[1][1]['selectTags']===['tag','value'] && isset($fixture->calls[1][1]['selectGroups']),'Selection fetches relations even when metric is templates');
$normalized=Config::getQualityPages($config);
okRule(Config::validateQualityPages($normalized)===$normalized,'Saved selection round trip');
foreach (['(A or B) and C', 'A or B and C', 'not (A or B) and C', 'not not A and (B or C)', '(A and B) or (not A and C)'] as $formula) {
    $program=Rules::compile($formula,3);
    for($bits=0;$bits<8;$bits++) {
        $a=(bool)($bits&1);$b=(bool)($bits&2);$c=(bool)($bits&4);
        switch($formula) {
            case '(A or B) and C':$expected=($a||$b)&&$c;break;
            case 'A or B and C':$expected=$a||($b&&$c);break;
            case 'not (A or B) and C':$expected=!($a||$b)&&$c;break;
            case 'not not A and (B or C)':$expected=$a&&($b||$c);break;
            default:$expected=($a&&$b)||(!$a&&$c);
        }
        okRule(Rules::evaluate($program,[$a,$b,$c])===$expected,'Boolean truth table: '.$formula);
    }
}
foreach (['', 'A', 'A or D', '(A and B', 'A B', 'A and', 'and A', 'A()', 'A or ()', 'A or B);phpinfo(', 'A || B', 'A E B', str_repeat('not ',130).'A', 'A and not or B'] as $invalid) {
    try { Rules::compile($invalid,2); okRule(false,'Invalid formula accepted: '.$invalid); }
    catch (InvalidArgumentException $e) { okRule(true,'Invalid formula rejected'); }
}
$custom=Rules::validate(['version'=>1,'mode'=>'custom','formula'=>'(A or B) and C','conditions'=>[$tag, array_replace($group,['value'=>'Missing']), $template]]);
okRule(Rules::matches($host,$custom),'Custom real host matching');
$config['quality_pages'][0]['cards'][0]['selection']=$custom;
$state=Calc::create($config,'main',[],Config::qualityRevision($config));
okRule(isset($state['cards'][0]['selection']['_program']),'Formula compiled once per calculation');
$preview=$state;$preview['preview']=true;$preview['preview_hosts']=[];
okRule($run($state,$fixture)['result']['kpis']===$run($preview,$fixture)['result']['kpis'],'Custom formula preview equals dashboard');
okRule(Config::getQualityPages($config)[0]['cards'][0]['selection']['formula']==='(A or B) and C','Custom formula roundtrip');
unset($fixture->rows['1']['tags']);
$broken=$run(Calc::create($config,'main',[],Config::qualityRevision($config)),$fixture);
okRule($broken['status']==='failed' && $broken['result']===null,'Incomplete API relationships never count as negative matches');
echo "PASS: $checks condition and preview assertions\n";
