<?php
//認証用のファイル
$credentialFile = './credential.json';
//サイトマップ
$sitemapOrIndexUrls = [];

//制限事項 // https://developers.google.com/search/apis/indexing-api/v3/quota-pricing
//1分以内に60回以上投げてはいけないので間隔をあける
$intervalSecondsPerAPI = 1;
//1日で投げられるAPIの上限(自分の上限に合わせて増やしてOK)
$limitPublishPerDay = 200;

require_once './vendor/autoload.php';

//コマンドラインパラメータ
foreach($argv as $n=>$v){
    if(startsWith($v, 'http://') || startsWith($v, 'https://') ){
        $sitemapOrIndexUrls[] = trim($v);
    }
}
if(empty($sitemapOrIndexUrls)){
    echo 'put sitemap or sitemap index url as commandline parameter';
    exit;
}

//URLからサイトマップ取りに行くところ
$options = ['http_errors' => false, 'debug' => false, 'verify'=>false, ];
$http = new GuzzleHttp\Client($options);

$list = [];

do{
    $url = array_shift($sitemapOrIndexUrls);
    echo '-getting XML >> ' . $url . PHP_EOL;

    $response = $http->request('GET', $url);
    if($response->getStatusCode() != 200){
        echo ' skip because status code (' . $response->getStatusCode() . ') is not valid.' . PHP_EOL;
        continue;
    }

    $body = $response->getBody()->getContents();
    $urlSet = new SimpleXMLElement($body);
    echo ' got ' . count($urlSet) . ' entries.' . PHP_EOL;

    foreach($urlSet as $name=>$data){
        $loc =  (String)$data->loc;
        if($name == 'sitemap'){
            $sitemapOrIndexUrls[] = $loc;
            echo ' sitemap URL :' . $loc . PHP_EOL;
        }elseif($name == 'url'){
            $sitemap = [];
            $sitemap['loc'] = (string)$data->loc;
            $sitemap['lastmod'] = toJST((string)$data->lastmod);
            $list[] = $sitemap;
            echo ' page :' . $sitemap['lastmod'] . ' ' . $sitemap['loc'] .PHP_EOL;
        }else{
            echo " somethign wrong <$name> tag." . PHP_EOL;
        }
    }
}while(!empty($sitemapOrIndexUrls));

//更新日の新しい順に並び替え（変わってないものはリクエスト不要）
foreach($list as $n => $sitemap){
    $sort_keys[$n] = $sitemap['lastmod'];
}
array_multisort($sort_keys, SORT_DESC, $list);

echo '=== URL ====' . PHP_EOL;
echo count($list)   . PHP_EOL;
echo '============' . PHP_EOL;

//Indexing API
$client = new Google_Client();
$client->setAuthConfig($credentialFile);
$client->addScope(Google_Service_Indexing::INDEXING);
$httpClient = $client->authorize($http);
$endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

$results = [];
foreach($list as $n=>$sitemap){
    if($limitPublishPerDay <= $n){
        break;
    }
    
    $param = [];
    $param['type'] = 'URL_UPDATED';
    $param['url'] = $sitemap['loc'];
    
    $response = $httpClient->post($endpoint, ['json' => $param]);
    $body = $response->getBody()->getContents();
    $json = json_decode($body, true);
    
    $status = $response->getStatusCode();
    $results[$status] = ($results[$status]??0) + 1;

    if($status == 429 ){
        echo 'reached api limitation.' . PHP_EOL;
        break;
    }else if($status==200){
        $time = toJST($json["urlNotificationMetadata"]["latestUpdate"]["notifyTime"]);
        echo $status . ':' . $response->getReasonPhrase() . '|' . $sitemap['loc'] . '|' .$time .PHP_EOL;
    }else{
        $message = $json['error']['message']??'-';
        echo $status . ':' . $response->getReasonPhrase() . '|' . $sitemap['loc'] . '|-|' .$message .PHP_EOL;
    }

    sleep($intervalSecondsPerAPI);
}

echo '=== Result ===' . PHP_EOL;
foreach($results as $status=>$count){
    echo $status . ':' . $count . PHP_EOL;
}
echo '==============' . PHP_EOL;


//Google APIのタイムスタンプがnano秒まであるので正規表現で削り取る
function toJST($datetime){
    $p = '/(\d{4})-(\d{2})-(\d{2})T(\d{2})\:(\d{2})\:(\d{2})\.[0-9]{9}Z/';
    if( preg_match($p, $datetime, $_)){
        $datetime = "$_[1]-$_[2]-$_[3]T$_[4]:$_[5]:$_[6]Z";
    }
    $t = new DateTime($datetime);
    $t->setTimeZone(new DateTimeZone('Asia/Tokyo'));
    return $t->format('Y-m-d H:i:s');
}

function startsWith($haystack, $needle){
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}