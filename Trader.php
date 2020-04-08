<?php

require __DIR__ . '/vendor/autoload.php';
require_once("BitMex.php");
require_once("log.php");


class Trader {

    const TICKER_PATH = '/.ticker.txt';
    private $log;
    private $bitmex;

    private $env;
    private $tradeSymbols = array('XBTUSD', 'ETHUSD','XBT7D_U105', 'ADAZ19', 'BCHZ19', 'EOSZ19', 'LTCZ19', 'TRXZ19', 'XRPZ19');
    private $tradeTypes = array('Buy', 'Sell');
    private $pid;

    private $symbol;
    private $stopLossInterval;
    private $targetPercent;
    private $type;
    private $amount;
    private $strategy;


    public function __construct($argv) {
        $config = include('config.php');
        $this->log = create_logger(getcwd().'/Trades.log');
        $this->bitmex = new BitMex($config['key'], $config['secret'], $config['testnet']);
        $this->symbol = $this->tradeSymbols[intval($argv[1])];
        $this->bitmex->setLeverage($config['leverage'], $this->symbol);

        $this->stopLossInterval = -$argv[2];
        $this->targetPercent = floatval($argv[3]) / 100;
        $this->type = $argv[4];
        $this->amount = $argv[5];

        if (isset($argv[6])) {
            $this->strategy = $argv[6];
        }

        if (isset($argv[7])) {
            $this->pid = $argv[7];
        }

        if ($config['testnet']) {
            $this->env = 'testing';
        }
        else {
            $this->env = 'prod';
        }

        $this->log->info("Trader Class initiated successfuly", ['enviroment'=>$this->env]);
    }

    public function is_buy() {
        return $this->type == 'Buy' ? 1:0;
    }

    public function get_opposite_trade_type() {
        return $this->type == "Buy" ? "Sell": "Buy";
    }

    public function get_ticker() {
        $ticker = fopen(getcwd().self::TICKER_PATH, "r");
        $lastPrice = floatval(fread($ticker, filesize(getcwd().self::TICKER_PATH)));
        fclose($ticker);
        return $lastPrice;
    }

    public function true_create_order($type, $amount) {
        $this->log->info("Sending a Create Order command", ['type'=>$type.''.$amount.' contracts']);
        do {
            try {
                $order = $this->bitmex->createOrder($this->symbol, "Market", $type, null, $amount);
            } catch (Exception $e) {
                if (strpos($e, 'Invalid orderQty') !== false) {
                    $this->log->error("Failed to sumbit, Invalid quantity", ['error'=>$e]);
                    return false;
                }
                if (strpos($e, 'insufficient Available Balance') !== false) {
                    $this->log->error("Failed to sumbit, insufficient Available Balance", ['error'=>$e]);
                    return false;
                }
                $this->log->error("Failed to create/close position retrying in 3 seconds", ['error'=>$e]);
                sleep(3);
                continue;
            }
            $this->log->info("Position has been created on Bitmex", ['info'=>$order]);
            break;
        } while (1);
        return true;
    }

    public function reverse_pos() {
        $nextPidFileName = 'reverse_'.$this->type.'_'.$this->pid;
        $currentPidFileName = 'reverse_'.$this->get_opposite_trade_type().'_'.$this->pid;
        if (file_exists($nextPidFileName)) {
            return;
        }
        if (file_exists($currentPidFileName) and !file_exists($nextPidFileName)) {
            if ($this->true_create_order($this->type, 2*$this->amount) != false) {
                shell_exec('rm '.$currentPidFileName);
                shell_exec('touch '.$nextPidFileName);
                return;
            }
            $this->log->error("Reverse position has Failed to create order", ['pid'=>$this->pid]);
            return;

        }
        elseif (!file_exists($nextPidFileName) and !file_exists($currentPidFileName)) {
            if ($this->true_create_order($this->type, $this->amount) != false) {
                shell_exec('touch '.$nextPidFileName);
                return;
            }
            $this->log->error("Reverse position has Failed to create order", ['pid'=>$this->pid]);
            return;
        }
        else {
            $this->log->error("Reverse position has Failed to operate due to Pid Files mismatch");
            return;
        }
    }

    public function with_id_trade($pidFileName) {
        $pidFileName = $this->type.'_with_id_'.$this->pid;

        if (file_exists($pidFileName)) {
            return;
        }
        else {
            shell_exec('touch '.$pidFileName);
        }
        $percentage1 = 0.4;
        $percentage2 = 0.2;

        $result = False;
        $openPrice = $this->get_ticker();
        $tradeInterval =  $this->is_buy() ? $openPrice * $this->targetPercent : - $openPrice * $this->targetPercent;
        $target = $openPrice + $tradeInterval;
        $fibArray = array(
            array(abs($tradeInterval), $percentage1 * $amount),
            array(abs(0.786 * $tradeInterval), $percentage1 * $this->amount),
            array(abs(0.618 * $tradeInterval), $percentage2 * $this->amount),
            );

        $this->true_create_order($this->amount);
        $this->log->info("Target is at price", ['Target'=>$target]);
        $this->log->info("Interval is", ['Interval'=>$fibArray]);
        $profitPair = array_pop($fibArray);
        $intervalFlag = true;

        do {
            $tmpLastPrice = $this->get_ticker();
            $openProfit = $this->is_buy() ? $tmpLastPrice - $openPrice: $openPrice - $tmpLastPrice;

            if ($openProfit < $this->stopLossInterval) {
                $this->log->info("Position reached it's close price, thus Closing.", ['Stop Loss'=>$tmpLastPrice]);
                $this->true_create_order(get_opposite_trade_type($type), $this->amount);
                $log->info("Trade has closed successfully", ['info'=>$close]);
                break;
            }
            elseif($openProfit > -$this->stopLossInterval and $intervalFlag) {
                $intervalFlag = false;
                $this->stopLossInterval = $this->stopLossInterval / 10;
                $this->log->info("Stop Loss has now changed to: ".$this->stopLossInterval, ['Profits'=>$openProfit]);
            }
            if ($openProfit > $profitPair[0]) {
                $this->log->info("A Target was reached", ['target'=>$profitPair[0]]);
                $this->true_create_order(get_opposite_trade_type($type), $profitPair[1]);
                $this->amount = $this->amount - $profitPair[1];
                if (sizeof($fibArray) > 0) {
                    $profitPair = array_pop($fibArray);
                }
            }
            sleep(1);
        } while ($amount);
        shell_exec('rm '.$pidFileName);
    }
}
?>
