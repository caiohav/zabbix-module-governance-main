<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../QualityCatalog.php';
use Modules\Governance\QualityCatalog as Catalog;
function checkCatalog($ok, $message) { if (!$ok) throw new RuntimeException($message); }
foreach ([['host','DBD'],['group','x'],['template',str_repeat('a',256)],['group',"DBD\n"],['group','  ']] as $input) {
    checkCatalog(!Catalog::valid(...$input), 'Invalid lookup rejected');
}
$calls=0;
$result=Catalog::search('template',' Linux ',static function($service,$options) use (&$calls) {
    $calls++;
    checkCatalog($service==='Template' && $options['search']===['name'=>'Linux','host'=>'Linux'], 'Search visible and technical names');
    checkCatalog($options['limit']===21 && $options['sortfield']==='name' && !$options['searchWildcardsEnabled'], 'Bounded sorted literal search');
    return array_fill(0,21,['templateid'=>'18446744073709551615','name'=>'Linux', 'host'=>'Linux technical']);
});
checkCatalog($calls===1 && count($result['items'])===20 && $result['has_more'], 'Single request, truncated catalog indicated');
checkCatalog($result['items'][0]['id']==='18446744073709551615','IDs preserve full precision');
$result=Catalog::search('group','DB',static function($service,$options) {
    checkCatalog($service==='HostGroup' && $options['output']===['groupid','name'], 'Minimal group fields');
    return [];
});
checkCatalog($result['items']===[] && !$result['has_more'], 'Empty result valid');
foreach ([false, [['groupid'=>'oops','name'=>'DB']], [['groupid'=>'12']]] as $bad) {
    try { Catalog::search('group','DB',static function() use ($bad) { return $bad; }); throw new LogicException('Should fail'); }
    catch (RuntimeException $e) { checkCatalog(!($e instanceof LogicException),'Malformed response rejected'); }
}
echo "PASS: bounded catalog lookup, validation and precision\n";
