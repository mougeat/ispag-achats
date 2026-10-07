<?php
define('ABSPATH','/x/'); $G=['admin'=>false,'upd'=>null];
function current_user_can($c){global $G;return $G['admin'];} function check_ajax_referer(){return true;} function __($s){return $s;}
function absint($v){return abs((int)$v);} function get_current_user_id(){return 1;}
function get_userdata($id){return in_array($id,[5,6])?(object)['display_name'=>"U$id"]:false;} function user_can($id,$c){return $id!=6;}
function wp_send_json_error($d,$c=0){throw new Exception('ERR '.json_encode($d));} function wp_send_json_success($d){throw new Exception('OK '.json_encode($d));}
class L{function log_db_change(){}} class ISPAG_Logger{static function get_instance(){return new L;}}
class W{public $prefix='wp_';function prepare($q,...$a){return $q;}function get_var($q){return 3;}function update($t,$d,$w){global $G;$G['upd']=$d;return 1;}} $wpdb=new W; $GLOBALS['wpdb']=$wpdb;
$src=file_get_contents('/home/user/ispag-achats/classes/class-ispag-achat-manager.php');
preg_match('/public static function ajax_set_manager\(\).*?\n    \}\n/s',$src,$m); eval('class X{ '.$m[0].' }');
function t($n,$c){echo ($c?'OK   ':'FAIL ').$n."\n";}
function run($post){ $_POST=$post; try{X::ajax_set_manager();}catch(Exception $e){return $e->getMessage();} }
$r=run(['order_id'=>3,'user_id'=>5]); t('non-admin refusé',strpos($r,'ERR')===0 && $G['upd']===null);
$G['admin']=true; $r=run(['order_id'=>3,'user_id'=>5]); t('admin OK',strpos($r,'OK')===0 && $G['upd']==['created_by'=>5]);
$G['upd']=null; $r=run(['order_id'=>3,'user_id'=>6]); t('utilisateur sans droit achat refusé',strpos($r,'ERR')===0 && $G['upd']===null);
$r=run(['order_id'=>3,'user_id'=>99]); t('utilisateur inconnu refusé',strpos($r,'ERR')===0);
