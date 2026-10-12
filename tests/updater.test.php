<?php
// Standalone updater contracts. Fake network and filesystem; no site data.
define('ABSPATH','/unused/');define('HOUR_IN_SECONDS',3600);define('MINUTE_IN_SECONDS',60);
if(!defined('MK_GITHUB_TOKEN'))define('MK_GITHUB_TOKEN','FAKE_LEGACY_TOKEN');
class WP_Error { public $code;public $message;public function __construct($code,$message){$this->code=$code;$this->message=$message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function plugin_basename($f){return 'admin-menu-manager/admin-menu-manager.php';}
function add_filter(...$args){$GLOBALS['qa_hooks'][]=$args;}
function add_action(...$args){$GLOBALS['qa_hooks'][]=$args;}
function get_file_data($file,$fields){return ['Version'=>'1.3.2'];}
function get_plugin_data($file,$markup,$translate){return ['Name'=>'Adminino','AuthorName'=>'Rino','Description'=>'Admin customization.'];}
function wp_kses_post($v){return strip_tags($v);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
function get_transient($k){return $GLOBALS['qa_cache'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['qa_cache'][$k]=$v;$GLOBALS['qa_ttl']=$ttl;}
function get_site_transient($k){return $GLOBALS['qa_site_cache'][$k]??false;}
function wp_safe_remote_get($url,$args){$GLOBALS['qa_requests'][]=[$url,$args];return $GLOBALS['qa_responses'][$url]??new WP_Error('network','offline');}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function wp_remote_retrieve_body($r){return $r['body']??'';}
function trailingslashit($v){return rtrim($v,'/').'/';}
function untrailingslashit($v){return rtrim($v,'/');}
class Fake_Filesystem {
 public $files=[];public $moves=[];
 public function get_contents($p){return $this->files[$p]??false;}
 public function is_file($p){return isset($this->files[$p]);}
 public function move($s,$t,$o){$this->moves[]=[$s,$t,$o];return true;}
}
require __DIR__.'/../includes/updater.php';
$GLOBALS['qa_count']=0;$GLOBALS['qa_hooks']=[];$GLOBALS['qa_requests']=[];$GLOBALS['qa_cache']=[];
function expect($ok,$label){$GLOBALS['qa_count']++;if(!$ok)throw new Exception($label);}
function response($code,$body=[]){return ['response'=>['code'=>$code],'body'=>json_encode($body)];}
$api='https://api.github.com/repos/rinothecoder/adminino';$sha=str_repeat('a',40);
$release=['tag_name'=>'v1.3.3','draft'=>false,'prerelease'=>false,'body'=>'<script>unsafe</script>'];
$GLOBALS['qa_responses']=[$api.'/releases/latest'=>response(200,$release),$api.'/commits/v1.3.3'=>response(200,['sha'=>$sha])];
$u=new MK_GitHub_Updater('/plugins/admin-menu-manager/admin-menu-manager.php','rinothecoder/adminino');
expect(count($GLOBALS['qa_hooks'])===5,'Only the five intended hooks are registered');
expect($u->info('sentinel','plugin_information',(object)['slug'=>'other'])==='sentinel','Unrelated plugin information unchanged');
expect(count($GLOBALS['qa_requests'])===0,'Other plugin does not make a GitHub request');
$state=(object)['checked'=>['admin-menu-manager/admin-menu-manager.php'=>'1.3.2'],'response'=>[]];
$offer=$u->check($state)->response['admin-menu-manager/admin-menu-manager.php'];
expect($offer->new_version==='1.3.3','Stable release is offered');
expect($offer->package===$api.'/zipball/'.$sha,'Download is pinned to exact repository commit');
expect($GLOBALS['qa_ttl']===21600,'Success cached six hours');
foreach($GLOBALS['qa_requests'] as $r){expect(empty($r[1]['headers']['Authorization']),'Legacy token is never sent');expect(empty($r[1]['cookies']),'Site cookies are never sent');expect($r[1]['redirection']===0,'API redirects disabled');}
$before=count($GLOBALS['qa_requests']);$u->check($state);expect(count($GLOBALS['qa_requests'])===$before,'Cache avoids repeated GitHub requests');
$info=$u->info(false,'plugin_information',(object)['slug'=>'admin-menu-manager']);expect(strpos($info->sections['changelog'],'<script>')===false,'Release notes are escaped');
$key='mk_gh_public_release_'.md5('rinothecoder/adminino');
foreach(['draft'=>true,'prerelease'=>true,'tag_name'=>'main'] as $field=>$bad){
 unset($GLOBALS['qa_cache'][$key]);$GLOBALS['qa_responses'][$api.'/releases/latest']=response(200,array_merge($release,[$field=>$bad]));
 $fresh=(object)['checked'=>['admin-menu-manager/admin-menu-manager.php'=>'1.3.2'],'response'=>[]];
 expect(empty($u->check($fresh)->response),'Ignore invalid or unreleased versions');expect($GLOBALS['qa_ttl']===900,'Failure cached fifteen minutes');
}
unset($GLOBALS['qa_cache'][$key]);$GLOBALS['qa_responses'][$api.'/releases/latest']=response(200,$release);$GLOBALS['qa_responses'][$api.'/commits/v1.3.3']=response(200,['sha'=>'main']);
$fresh=(object)['checked'=>['admin-menu-manager/admin-menu-manager.php'=>'1.3.2'],'response'=>[]];expect(empty($u->check($fresh)->response),'Invalid release commit is rejected');
unset($GLOBALS['qa_cache'][$key]);$GLOBALS['qa_responses'][$api.'/commits/v1.3.3']=response(200,['sha'=>$sha]);
$fresh=(object)['checked'=>['admin-menu-manager/admin-menu-manager.php'=>'1.3.3'],'response'=>[]];expect(empty($u->check($fresh)->response),'Installed version is not offered again');
$GLOBALS['qa_site_cache']['update_plugins']=(object)['response'=>['admin-menu-manager/admin-menu-manager.php'=>$offer]];
$GLOBALS['wp_filesystem']=new Fake_Filesystem;$source='/tmp/github-hash/';
$GLOBALS['wp_filesystem']->files[$source.'admin-menu-manager.php']="<?php\n/**\n * Plugin Name: Adminino\n * Version: 1.3.3\n */";
foreach(['includes/design.php','includes/look.php','includes/extras.php','includes/ordering.php','includes/help.php','includes/updater.php','languages/nl.php','assets/lucide.json','assets/adminino-icon.svg','assets/adminino-sidebar.svg'] as $f)$GLOBALS['wp_filesystem']->files[$source.$f]='runtime';
expect($u->fix_dir($source,'',null,['plugin'=>'other/other.php'])===$source,'Other extracted plugin untouched');
expect($u->fix_dir($source,'',null,['plugin'=>'admin-menu-manager/admin-menu-manager.php'])==='/tmp/admin-menu-manager/','Installed plugin directory preserved');
$GLOBALS['wp_filesystem']->files[$source.'admin-menu-manager.php']=str_replace('1.3.3','1.3.2',$GLOBALS['wp_filesystem']->files[$source.'admin-menu-manager.php']);
expect(is_wp_error($u->fix_dir($source,'',null,['plugin'=>'admin-menu-manager/admin-menu-manager.php'])),'Archive version mismatch rejected');
$GLOBALS['wp_filesystem']->files[$source.'admin-menu-manager.php']=str_replace('1.3.2','1.3.3',$GLOBALS['wp_filesystem']->files[$source.'admin-menu-manager.php']);
unset($GLOBALS['wp_filesystem']->files[$source.'includes/look.php']);expect(is_wp_error($u->fix_dir($source,'',null,['plugin'=>'admin-menu-manager/admin-menu-manager.php'])),'Incomplete plugin rejected');
unset($GLOBALS['qa_cache'][$key]);$GLOBALS['qa_responses'][$api.'/releases/latest']=new WP_Error('network','offline');
$fresh=(object)['checked'=>['admin-menu-manager/admin-menu-manager.php'=>'1.3.2'],'response'=>[]];expect(empty($u->check($fresh)->response),'Offline updater leaves installed plugin usable');
echo 'PASS: '.$GLOBALS['qa_count'].' updater checks'."\n";
