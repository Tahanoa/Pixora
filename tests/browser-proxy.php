<?php
/** CLI-only bridge into a disposable container; no public test endpoint. */
if(PHP_SAPI!=='cli')exit;
while(($line=fgets(STDIN))!==false){
 $q=json_decode($line,true);$headers=array();$response_headers=array();
 foreach($q['headers']as$key=>$value)if(!in_array(strtolower($key),array('accept-encoding','content-length'),true))$headers[]=$key.': '.$value;
 $curl=curl_init('http://127.0.0.1'.$q['path']);
 curl_setopt_array($curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$q['method'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>45,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>function($curl,$line)use(&$response_headers){$pos=strpos($line,':');if($pos!==false){$key=strtolower(trim(substr($line,0,$pos)));if(!in_array($key,array('transfer-encoding','content-length'),true))$response_headers[$key]=trim(substr($line,$pos+1));}return strlen($line);}));
 if(isset($q['body']))curl_setopt($curl,CURLOPT_POSTFIELDS,base64_decode($q['body']));
 $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
 echo json_encode(array('id'=>$q['id'],'status'=>$status,'headers'=>$response_headers,'body'=>base64_encode($body===false?'':$body),'error'=>$error))."\n";fflush(STDOUT);
}
