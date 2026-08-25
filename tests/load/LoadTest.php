<?php
// SWASTHYA Load Test - Phase 34
$BASE_URL = getopt("", ["base-url:"])["base-url"] ?? "http://127.0.0.1:8000";
$CONCURRENCY = (int)(getopt("", ["concurrency:"])["concurrency"] ?? 10);
$TOTAL = (int)(getopt("", ["requests:"])["requests"] ?? 100);
$TOKEN = getenv("SWASTHYA_LOAD_TOKEN") ?: "";
$ENDPOINTS = [
    ["name"=>"POST /auth/login","method"=>"POST","path"=>"/api/v1/auth/login","body"=>json_encode(["email"=>"admin@swasthya.test","password"=>"password"])],
    ["name"=>"GET /patients","method"=>"GET","path"=>"/api/v1/patients?search=ra"],
    ["name"=>"GET /appointments","method"=>"GET","path"=>"/api/v1/appointments"],
    ["name"=>"GET /encounters","method"=>"GET","path"=>"/api/v1/encounters"],
    ["name"=>"GET /dashboard/metrics","method"=>"GET","path"=>"/api/v1/dashboard/metrics"],
    ["name"=>"GET /prescriptions","method"=>"GET","path"=>"/api/v1/prescriptions"],
    ["name"=>"GET /lab-orders","method"=>"GET","path"=>"/api/v1/lab-orders"],
    ["name"=>"GET /finance/overview","method"=>"GET","path"=>"/api/v1/finance/overview"],
    ["name"=>"GET /inventory","method"=>"GET","path"=>"/api/v1/inventory"],
];
echo "SWASTHYA Load Test - Phase 34
";
echo "URL: $BASE_URL | Concurrency: $CONCURRENCY | Requests: $TOTAL

";
if (!$TOKEN) {
    $lr = doReq("POST", $BASE_URL."/api/v1/auth/login", json_encode(["email"=>"admin@swasthya.test","password"=>"password"]));
    if ($lr["s"]===200) { $b=json_decode($lr["b"],true); $TOKEN=$b["data"]["accessToken"]??""; echo "Token obtained
"; }
    else echo "Login failed (".$lr["s"].")
";
}
echo "Warmup...
";
foreach ($ENDPOINTS as $ep) { $r = doReq($ep["method"], $BASE_URL.$ep["path"], $ep["body"]??null, $TOKEN); echo "  ".($r["s"]>=200&&$r["s"]<400?"OK":"ERR(".$r["s"].")")." ".$ep["name"]." ".$r["t"]."ms
"; }
echo "
Load test...
"; $results = []; $start = microtime(true); $done = 0;
while ($done < $TOTAL) { $batch = min($CONCURRENCY, $TOTAL - $done); $mh = curl_multi_init(); $hs = [];
    for ($i=0; $i<$batch; $i++) { $ep = $ENDPOINTS[($done+$i) % count($ENDPOINTS)]; $url = $BASE_URL.$ep["path"]; $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_CUSTOMREQUEST=>$ep["method"], CURLOPT_HTTPHEADER=>array_filter(["Content-Type: application/json",$TOKEN?"Authorization: Bearer ".$TOKEN:null])]);
        if (($ep["body"]??null) && in_array($ep["method"],["POST","PUT","PATCH"])) curl_setopt($ch, CURLOPT_POSTFIELDS, $ep["body"]);
        $hs[] = ["h"=>$ch,"n"=>$ep["name"]]; curl_multi_add_handle($mh, $ch); }
    do { curl_multi_exec($mh,$act); } while($act);
    foreach ($hs as $info) { $ch=$info["h"]; $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $time=curl_getinfo($ch,CURLINFO_TOTAL_TIME)*1000;
        $results[]=["n"=>$info["n"],"s"=>$code,"t"=>round($time,1)]; curl_multi_remove_handle($mh,$ch); curl_close($ch); }
    curl_multi_close($mh); $done += $batch; }
$elapsed = round(microtime(true)-$start, 2); $byEp = []; foreach ($results as $r) $byEp[$r["n"]][]=$r;
echo "
".str_pad("Endpoint",28).str_pad("Reqs",6).str_pad("p50",8).str_pad("p95",8).str_pad("p99",8).str_pad("Err",5)."
";
echo str_repeat("-",55)."
";
$allT=[]; $ok=0; $fail=0;
foreach ($byEp as $name=>$eps) { $ts=array_column($eps,"t"); sort($ts); $c=count($ts);
    $p50=$ts[(int)($c*0.5)]??0; $p95=$ts[(int)($c*0.95)]??0; $p99=$ts[(int)($c*0.99)]??0;
    $errs=count(array_filter($eps,fn($r)=>$r["s"]<200||$r["s"]>=400)); $allT=array_merge($allT,$ts); $ok+=count($eps)-$errs; $fail+=$errs;
    echo str_pad($name,28).str_pad($c,6).str_pad(round($p50,1),8).str_pad(round($p95,1),8).str_pad(round($p99,1),8).str_pad($errs,5)."
"; }
sort($allT); $tc=count($allT); $p50=$allT[(int)($tc*0.5)]??0; $p95=$allT[(int)($tc*0.95)]??0; $p99=$allT[(int)($tc*0.99)]??0;
echo str_repeat("-",55)."
";
echo str_pad("OVERALL",28).str_pad($tc,6).str_pad(round($p50,1),8).str_pad(round($p95,1),8).str_pad(round($p99,1),8).str_pad($fail,5)."
";
$rps = round($tc/max(0.001,$elapsed),1); $errPct = round($fail/max(1,$tc)*100,1);
echo "
Total: ".$elapsed."s | ".$tc." reqs | ".$ok." ok | ".$fail." fail | ".$rps." r/s | err:".$errPct."%
";
$out=["timestamp"=>date("c"),"config"=>["url"=>$BASE_URL,"concurrency"=>$CONCURRENCY,"requests"=>$TOTAL],"summary"=>["time_s"=>$elapsed,"total"=>$tc,"ok"=>$ok,"fail"=>$fail,"rps"=>$rps,"p50"=>round($p50,1),"p95"=>round($p95,1),"p99"=>round($p99,1)],"by_endpoint"=>[]];
foreach ($byEp as $n=>$eps) { $ts=array_column($eps,"t"); sort($ts); $c=count($ts); $out["by_endpoint"][$n]=["count"=>$c,"p50"=>round($ts[(int)($c*0.5)]??0,1),"p95"=>round($ts[(int)($c*0.95)]??0,1),"p99"=>round($ts[(int)($c*0.99)]??0,1),"errors"=>count(array_filter($eps,fn($r)=>$r["s"]<200||$r["s"]>=400))]; }
file_put_contents(__DIR__."/results.json", json_encode($out, JSON_PRETTY_PRINT)); echo "
Results saved to tests/load/results.json
";
function doReq($method,$url,$body=null,$token="") { $s=microtime(true); $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_filter(["Content-Type: application/json",$token?"Authorization: Bearer ".$token:null])]);
    if($body&&in_array($method,["POST","PUT","PATCH"])) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return ["s"=>$code,"b"=>$resp?"":"","t"=>round((microtime(true)-$s)*1000,1)]; }
?>