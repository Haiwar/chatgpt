<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/event-stream");
$log = fopen(__DIR__ . "/chat.txt", "a") or die("Writing file failed.");
$lines = explode("----------------", $log); // 分离每行日志
$ips = array(); // 用于存储IP地址及其发布的次数
foreach ($lines as $line) {
    if (strpos($line, "|") !== false) { // 如果这行包含IP地址
        $ip = trim(explode("|", $line)[0]); // 获取IP地址并去除空格
        $date = explode(" ", trim(explode("|", $line)[1]))[0]; // 获取日期
        if ($date == date("Y-m-d")) { // 如果日期是当天
            if (!isset($ips[$ip])) {
                $ips[$ip] = 0;
            }
            $ips[$ip]++; // 增加这个IP地址的发布次数
        }
    }
}
$limit = 30;
foreach ($ips as $ip => $count) {
    if($_SERVER["REMOTE_ADDR"] == $ip && $count >= $limit) {
        echo "您今天已经超过了 $limit 次了，明天再来吧！";
        die;
    }
}
session_start();
$postData = $_SESSION['data'];
$_SESSION['response'] = "";
$ch = curl_init();
$OPENAI_API_KEY = "xxxx";
if ((isset($_SESSION['key'])) && (!empty($_POST['key']))) {
    $OPENAI_API_KEY = $_SESSION['key'];
}
$headers  = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $OPENAI_API_KEY
];

setcookie("errcode", ""); //EventSource无法获取错误信息，通过cookie传递
setcookie("errmsg", "");

$callback = function ($ch, $data) {
    $complete = json_decode($data);
    if (isset($complete->error)) {
        setcookie("errcode", $complete->error->code);
        setcookie("errmsg", $data);
        if (strpos($complete->error->message, "Rate limit reached") === 0) { //访问频率超限错误返回的code为空，特殊处理一下
            setcookie("errcode", "rate_limit_reached");
        }
    } else {
        echo $data;
        $_SESSION['response'] .= $data;
    }
    return strlen($data);
};

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
curl_setopt($ch, CURLOPT_URL, 'https://openai.1rmb.tk/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);
//curl_setopt($ch, CURLOPT_PROXY, "http://127.0.0.1:1081");

curl_exec($ch);

$answer = "";
$responsearr = explode("data: ", $_SESSION['response']);

foreach ($responsearr as $msg) {
    $contentarr = json_decode(trim($msg), true);
    if (isset($contentarr['choices'][0]['delta']['content'])) {
        $answer .= $contentarr['choices'][0]['delta']['content'];
    }
}

$questionarr = json_decode($_SESSION['data'], true);
$filecontent = $_SERVER["REMOTE_ADDR"] . " | " . date("Y-m-d H:i:s") . "\n";
$filecontent .= "Q:" . end($questionarr['messages'])['content'] .  "\nA:" . trim($answer) . "\n----------------\n";
$myfile = fopen(__DIR__ . "/chat.txt", "a") or die("Writing file failed.");
fwrite($myfile, $filecontent);
fclose($myfile);
curl_close($ch);
