<?php
/**
 * EchoTech POS - ZRA ZRA Integration queue worker.
 *
 * CLI is preferred. It can also be invoked by a protected scheduler URL only
 * if the host provides a secure scheduler secret. It never sends data unless
 * the ZRA device is initialized and the queue entry belongs to that pharmacy.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){
    $secret=(string)($_GET['secret']??'');$expected=trim((string)(getenv('ECHOTECH_ZRA_WORKER_SECRET')?:''));
    if($expected===''||!hash_equals($expected,$secret)){http_response_code(403);exit('Forbidden.');}
}
foreach([__DIR__.'/../../includes/conn.php',__DIR__.'/../../config.php',__DIR__.'/../../db.php'] as $f){if(is_file($f)){require_once $f;if(isset($conn)&&$conn instanceof mysqli)break;}}
require_once __DIR__.'/zra_client.php';
if(!isset($conn)||!($conn instanceof mysqli))exit("Database connection unavailable.\n");
$conn->set_charset('utf8mb4');
$pharmacyId=(int)(getenv('ECHOTECH_ZRA_WORKER_PHARMACY_ID')?:0);if($pharmacyId<=0){if(PHP_SAPI==='cli'){fwrite(STDERR,"Set ECHOTECH_ZRA_WORKER_PHARMACY_ID for CLI worker.\n");exit(2);}exit('Worker pharmacy not configured.');}
$settings=[];$s=$conn->prepare('SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1');if($s){$s->bind_param('i',$pharmacyId);$s->execute();$settings=$s->get_result()->fetch_assoc()?:[];$s->close();}$env=(string)($settings['smart_invoice_environment']??'test');
$s=$conn->prepare("SELECT * FROM zra_queue WHERE pharmacy_id=? AND status IN ('queued','failed') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id ASC LIMIT 10");if(!$s)exit("Unable to read queue.\n");$s->bind_param('i',$pharmacyId);$s->execute();$r=$s->get_result();$rows=[];while($x=$r->fetch_assoc())$rows[]=$x;$s->close();
foreach($rows as $row){$id=(int)$row['id'];$branch=(int)$row['branch_id'];$d=[];$s=$conn->prepare('SELECT * FROM zra_devices WHERE pharmacy_id=? AND branch_id=? AND environment=? AND initialized=1 LIMIT 1');if($s){$s->bind_param('iis',$pharmacyId,$branch,$env);$s->execute();$d=$s->get_result()->fetch_assoc()?:[];$s->close();}if(!$d){echo "Queue {$id}: device not initialized\n";continue;}$payload=json_decode((string)$row['payload'],true);if(!is_array($payload)){echo "Queue {$id}: invalid JSON\n";continue;}$u=$conn->prepare('UPDATE zra_queue SET status="processing",attempts=attempts+1,last_attempt_at=NOW() WHERE id=? AND pharmacy_id=?');if($u){$u->bind_param('ii',$id,$pharmacyId);$u->execute();$u->close();}$res=zra_http_post((string)$d['base_url'],(string)$row['endpoint'],$payload);$j=$res['json'];$code=is_array($j)?(string)($j['resultCd']??''):'';$msg=is_array($j)?(string)($j['resultMsg']??''):($res['error']??'');$status=$res['ok']?'submitted':(($res['http_status']>=400&&$res['http_status']<500)?'rejected':'failed');$resp=(string)($res['body']??'');$u=$conn->prepare('UPDATE zra_queue SET status=?,http_status=?,result_code=?,result_message=?,response_payload=?,submitted_at=IF(?="submitted",NOW(),submitted_at),next_attempt_at=IF(?="failed",DATE_ADD(NOW(),INTERVAL LEAST(attempts*5,60) MINUTE),NULL) WHERE id=? AND pharmacy_id=?');if($u){$u->bind_param('sisssssii',$status,$res['http_status'],$code,$msg,$resp,$status,$status,$id,$pharmacyId);$u->execute();$u->close();}echo "Queue {$id}: {$status} {$code} {$msg}\n";}
