<?php
declare(strict_types=1);
/**
 * EchoTech POS - ZRA VSDC transport adapter
 *
 * Uses the taxpayer's registered VSDC device. The current ZRA specification
 * documents POST /trnsSales/saveSales for normal sales. The VSDC device is
 * initialized separately and supplies its own authentication material.
 */
function zra_vsdc_table_columns(mysqli $conn, string $table): array
{
    $out=[]; $safe=$conn->real_escape_string($table);
    $r=@$conn->query("SHOW COLUMNS FROM `{$safe}`");
    if($r instanceof mysqli_result) while($x=$r->fetch_assoc()) $out[]=(string)$x['Field'];
    return $out;
}
function zra_vsdc_pick(array $cols, array $names): ?string
{
    foreach($names as $n) if(in_array($n,$cols,true)) return $n;
    return null;
}
function zra_vsdc_row(mysqli $conn,string $sql,string $types,array $params):?array
{
    $s=$conn->prepare($sql); if(!$s) return null; $s->bind_param($types,...$params); $s->execute(); $r=$s->get_result(); $row=$r?$r->fetch_assoc():null; $s->close(); return $row?:null;
}
function zra_vsdc_post(string $url,array $payload,int $timeout=20):array
{
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false]);
    $body=curl_exec($ch); $errno=curl_errno($ch); $error=curl_error($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($errno!==0) throw new RuntimeException('VSDC connection failed: '.$error);
    $decoded=json_decode((string)$body,true);
    if(!is_array($decoded)) throw new RuntimeException('VSDC returned a non-JSON response (HTTP '.$http.').');
    $decoded['_http_status']=$http; return $decoded;
}
function zra_vsdc_payment_code(string $method):?string
{
    // pmtTyCd is optional in the ZRA Save Sales request. We only send a code
    // when a code is explicitly known/configured by the POS integration.
    $m=strtolower(trim($method));
    $map=['cash'=>'01'];
    return $map[$m]??null;
}
function zra_phase2_submit_invoice(array $payload):array
{
    global $conn;
    if(!isset($conn)||!($conn instanceof mysqli)) return ['success'=>false,'status'=>'pending','message'=>'Database connection unavailable.'];
    $pharmacyId=(int)$payload['pharmacy_id']; $branchId=(int)$payload['branch_id'];

    if(!function_exists('zra_vsdc_table_columns')) return ['success'=>false,'status'=>'pending','message'=>'VSDC adapter unavailable.'];
    $settings=null;
    $cols=zra_vsdc_table_columns($conn,'compliance_settings');
    if($cols){
        $tpinCol=zra_vsdc_pick($cols,['tpin','taxpayer_tpin']);
        if($tpinCol){$settings=zra_vsdc_row($conn,"SELECT `{$tpinCol}` tpin FROM compliance_settings WHERE pharmacy_id=? LIMIT 1",'i',[$pharmacyId]);}
    }
    $tpin=trim((string)($settings['tpin']??$payload['taxpayer_tpin']??''));
    if($tpin===''||strlen($tpin)!==10) return ['success'=>false,'status'=>'rejected','message'=>'ZRA TPIN is missing or is not 10 digits.'];

    if(!zra_vsdc_table_columns($conn,'zra_devices')) return ['success'=>false,'status'=>'pending','message'=>'No zra_devices table is available. Initialize the registered VSDC device first.'];
    $dc=zra_vsdc_table_columns($conn,'zra_devices');
    $baseCol=zra_vsdc_pick($dc,['base_url','baseUrl','endpoint','vsdc_endpoint']);
    $bhfCol=zra_vsdc_pick($dc,['bhf_id','bhfId','branch_id_code']);
    $envCol=zra_vsdc_pick($dc,['environment','env']);
    $serialCol=zra_vsdc_pick($dc,['device_serial','device_serial_no','dvc_srl_no','dvcSrlNo']);
    if(!$baseCol||!$bhfCol) return ['success'=>false,'status'=>'pending','message'=>'VSDC device configuration is incomplete: base URL and branch ID are required.'];
    $select="`{$baseCol}` base_url,`{$bhfCol}` bhf_id".($envCol?",`{$envCol}` environment":'').($serialCol?",`{$serialCol}` device_serial":'');
    $device=zra_vsdc_row($conn,"SELECT {$select} FROM zra_devices WHERE pharmacy_id=? AND branch_id=? ORDER BY id DESC LIMIT 1",'ii',[$pharmacyId,$branchId]);
    if(!$device) return ['success'=>false,'status'=>'pending','message'=>'This branch has no registered VSDC device. Register and initialize it before submitting invoices.'];
    $base=rtrim(trim((string)$device['base_url']),'/'); $bhf=str_pad((string)$device['bhf_id'],3,'0',STR_PAD_LEFT);
    if($base==='') return ['success'=>false,'status'=>'pending','message'=>'VSDC base URL is empty.'];

    // Build ZRA item lines from the existing ZRA item registry. No guessed
    // classification/tax codes are accepted.
    if(!zra_vsdc_table_columns($conn,'zra_items')) return ['success'=>false,'status'=>'pending','message'=>'ZRA item registry is not installed. Complete ZRA item mapping first.'];
    $ic=zra_vsdc_table_columns($conn,'zra_items');
    $linkCol=zra_vsdc_pick($ic,['store_item_id','product_id','item_id','pos_item_id']);
    $itemCodeCol=zra_vsdc_pick($ic,['item_code','itemCd','zra_item_code']);
    $classCol=zra_vsdc_pick($ic,['itemClsCd','item_cls_cd','classification_code']);
    $vatCatCol=zra_vsdc_pick($ic,['vatCatCd','vat_cat_cd','vat_category']);
    $taxRateCol=zra_vsdc_pick($ic,['tax_rate','vat_rate','taxRt']);
    $pkgCol=zra_vsdc_pick($ic,['pkgUnitCd','pkg_unit_cd','packaging_unit']);
    $qtyCol=zra_vsdc_pick($ic,['qtyUnitCd','qty_unit_cd','quantity_unit']);
    $barcodeCol=zra_vsdc_pick($ic,['bcd','barcode']);
    $itemTypeCol=zra_vsdc_pick($ic,['itemTyCd','item_ty_cd','item_type']);
    if(!$linkCol||!$itemCodeCol||!$classCol||!$vatCatCol||!$pkgCol||!$qtyCol) return ['success'=>false,'status'=>'pending','message'=>'One or more required ZRA item mapping fields are missing. Map item code, classification, VAT category, packaging and quantity unit.'];

    $items=[]; $taxBuckets=['A'=>0.0,'B'=>0.0,'C1'=>0.0,'C2'=>0.0,'C3'=>0.0,'D'=>0.0,'E'=>0.0,'F'=>0.0]; $taxAmts=['A'=>0.0,'B'=>0.0,'C1'=>0.0,'C2'=>0.0,'C3'=>0.0,'D'=>0.0,'E'=>0.0,'F'=>0.0];
    foreach(($payload['items']??[]) as $idx=>$line){
        $pid=(int)$line['product_id'];
        $select="*"; $map=zra_vsdc_row($conn,"SELECT {$select} FROM zra_items WHERE `{$linkCol}`=? AND pharmacy_id=? AND branch_id=? ORDER BY id DESC LIMIT 1",'iii',[$pid,$pharmacyId,$branchId]);
        if(!$map) return ['success'=>false,'status'=>'pending','message'=>'Product #'.$pid.' is not mapped to ZRA. Open ZRA Items and complete its mapping before selling this item.'];
        $itemCode=trim((string)$map[$itemCodeCol]); $itemClass=trim((string)$map[$classCol]); $vatCat=strtoupper(trim((string)$map[$vatCatCol]));
        $pkg=trim((string)$map[$pkgCol]); $qtyUnit=trim((string)$map[$qtyCol]);
        if($itemCode===''||$itemClass===''||$vatCat===''||$pkg===''||$qtyUnit==='') return ['success'=>false,'status'=>'pending','message'=>'Incomplete ZRA mapping for product #'.$pid.'.'];
        $rate=$taxRateCol!==null?(float)($map[$taxRateCol]??0):($vatCat==='A'?16.0:0.0);
        $inclusive=round((float)$line['amount'],4); $qty=(float)$line['quantity']; $unit=round((float)$line['unit_price'],4);
        $exclusive=$rate>0?round($inclusive/(1+$rate/100),4):$inclusive; $vat=round($inclusive-$exclusive,4);
        if(isset($taxBuckets[$vatCat])){$taxBuckets[$vatCat]+=$exclusive;$taxAmts[$vatCat]+=$vat;}
        $items[]=['itemSeq'=>$idx+1,'itemCd'=>$itemCode,'itemClsCd'=>$itemClass,'itemNm'=>$line['description'],'bcd'=>$barcodeCol?(string)($map[$barcodeCol]??''):'','pkgUnitCd'=>$pkg,'pkg'=>0,'qtyUnitCd'=>$qtyUnit,'qty'=>$qty,'prc'=>$unit,'splyAmt'=>$inclusive,'dcRt'=>0,'dcAmt'=>0,'vatCatCd'=>$vatCat,'vatTaxblAmt'=>$exclusive,'vatAmt'=>$vat,'totAmt'=>$inclusive];
    }
    $created=strtotime((string)$payload['invoice_date'])?:time(); $cfm=date('YmdHis',$created); $salesDt=date('Ymd',$created);
    $req=['tpin'=>$tpin,'bhfId'=>$bhf,'orgInvcNo'=>0,'cisInvcNo'=>(string)$payload['local_invoice_no'],'custTpin'=>'','custNm'=>'','salesTyCd'=>'N','rcptTyCd'=>'S','salesSttsCd'=>'02','cfmDt'=>$cfm,'salesDt'=>$salesDt,'stockRlsDt'=>null,'cnclReqDt'=>null,'cnclDt'=>null,'rfdDt'=>null,'rfdRsnCd'=>null,'totItemCnt'=>count($items)];
    foreach(['A','B','C1','C2','C3','D','E','F'] as $c){$req['taxblAmt'.$c]=round($taxBuckets[$c],4);$req['taxAmt'.$c]=round($taxAmts[$c],4);$req['taxRt'.$c]=$c==='A'?16:0;}
    $req+=['taxblAmtRvat'=>0,'taxblAmtIpl1'=>0,'taxblAmtIpl2'=>0,'taxblAmtTl'=>0,'taxblAmtEcm'=>0,'taxblAmtExeeg'=>0,'taxblAmtTot'=>round(array_sum($taxBuckets),4),'taxRtRvat'=>16,'taxRtIpl1'=>5,'taxRtIpl2'=>0,'taxRtTl'=>1.5,'taxRtEcm'=>5,'taxRtExeeg'=>3,'taxRtTot'=>0,'taxAmtRvat'=>0,'taxAmtIpl1'=>0,'taxAmtIpl2'=>0,'taxAmtTl'=>0,'taxAmtEcm'=>0,'taxAmtExeeg'=>0,'taxAmtTot'=>round(array_sum($taxAmts),4),'cashDcRt'=>0,'cashDcAmt'=>0,'totTaxblAmt'=>round(array_sum($taxBuckets),4),'totTaxAmt'=>round(array_sum($taxAmts),4),'totAmt'=>round((float)$payload['total'],4),'prchrAcptcYn'=>'N','remark'=>'','regrId'=>'ECHOTECH','regrNm'=>'ECHOTECH','modrId'=>'ECHOTECH','modrNm'=>'ECHOTECH','saleCtyCd'=>'1','lpoNumber'=>null,'currencyTyCd'=>'ZMW','exchangeRt'=>'1','destnCountryCd'=>'','dbtRsnCd'=>'','invcAdjustReason'=>'','itemList'=>$items];
    $pmt=zra_vsdc_payment_code((string)$payload['payment_method']); if($pmt!==null)$req['pmtTyCd']=$pmt;
    $response=zra_vsdc_post($base.'/trnsSales/saveSales',$req,25); $http=(int)($response['_http_status']??0); unset($response['_http_status']);
    $code=(string)($response['resultCd']??''); $message=(string)($response['resultMsg']??''); $data=is_array($response['data']??null)?$response['data']:[];
    $success=in_array($code,['000','00'],true);
    return ['success'=>$success,'status'=>$success?'accepted':'rejected','response_code'=>$code,'message'=>$message,'raw'=>$response,'zra_invoice_no'=>(string)($data['rcptNo']??''),'sdc_id'=>(string)($data['sdcId']??''),'signature'=>(string)($data['rcptSign']??''),'internal_data'=>(string)($data['intrlData']??''),'receipt_url'=>(string)($data['qrCodeUrl']??''),'http_status'=>$http];
}
