<?php
require __DIR__ . '/vendor/autoload.php';
require_once("GmailClient.php");
require_once("BitMex.php");
require_once('log.php');
$config = include('config.php');

$logPath =  getcwd().'/index.log';
$log = create_logger($logPath);
$ticker =".ticker.txt";
$oldMessagesIds = false;

function verify_trade_exists($argv) {
    $tradeTypes = array('Buy', 'Sell');
    if (isset($argv[3]) and in_array($argv[3], $tradeTypes)) {
    $type = $argv[3];
    }
    if (isset($argv[5]) and $argv[5] == "reverse_pos") {
        $pid = $argv[6];
        $currentFileName = 'reverse_'.($type).'_'.$pid;
        if (file_exists($currentFileName)) {
            return false;
        }
    }
    if (isset($argv[5]) and $argv[5] == "with_id") {
        $pid = $argv[6];
        $currentFileName = $type.'_with_id_'.$pid;
        if (file_exists($currentFileName)) {
            return false;
        }
    }
    return true;
}

$bitmex = new BitMex($config['key'],$config['secret']);
$log->info("Bot index has started", ['info'=>'start']);

$gmailClient = new GmailClient();
try {
    $gmailClient->populateMessagesIds();
} catch (Exception $e) {
    $log->error('An error occurred at populateMessagesIds: ', ['error'=>$e->getMessage()]);
}



while(1) {
    try {
        $result = $bitmex->getTicker('XBTUSD');
        file_put_contents($ticker,  strval($result["last"]));
    } catch (Exception $e) {
        $log->error("Failed retrieving ticker", ['error'=>$e]);
    }

    try {
        $newMessagesIds = $gmailClient->getNewMessagesIds();
    } catch (Exception $e) {
        $log->error('An error occurred at getLastMessages: ', ['error'=>$e->getMessage()]);
    }

    if(empty($newMessagesIds)) {
        sleep(60);
    }

    else {
        $log->info("New messages fetched", ['messages'=>$newMessagesIds]);
        foreach($newMessagesIds as $msgId) {
            try {
                $msg = $gmailClient->getMessage($msgId);
                $params = $gmailClient->getAlertSubject($msg);
            } catch (Exception $e) {
                $log->error('An error occurred at getMessage or getAlertSubject: ', ['error'=>$e->getMessage()]);
            }
            if (verify_trade_exists(explode(' ', $params))) {
                $command = 'php CreateTrade.php '.$params.' > /dev/null 2>&1 &';
                $res = $gmailClient->isMessageAlert($msg);
                $res == True ? shell_exec('php CreateTrade.php '.$params.' > /dev/null 2>&1 &') : "Not alert message\n";
                $log->info('Command was sent to Trader '.$command, ['msg id'=>$msgId]);
            }
            $gmailClient->populateMessageId($msgId);
        }
    }
}
?>
