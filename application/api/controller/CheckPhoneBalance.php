<?php

namespace app\api\controller;

use think\Controller;
use think\Request;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\common\Redis;

class CheckPhoneBalance extends Command
{


    protected function configure()
    {
        $this->setName('CheckPhoneBalance')->setDescription('Here is the CheckPhoneBalance');
    }

    protected function execute(Input $input, Output $output)
    {
        $redis = Redis();
        $redis->setOption();
        // $redis->psubscribe(array('__keyevent@0__:expired'), 'keyCallback');
        $redis->psubscribe(array('__keyevent@0__:expired'), function ($redis, $pattern, $channel, $msg){
            echo PHP_EOL;
            echo "Pattern: $pattern\n";
            echo "Channel: $channel\n";
            echo "Payload: $msg\n\n";
            //................

            /*TODO处理业务逻辑*/

        });



        $output->writeln("TestCommand:998998998");
    }



    // public static function keyCallback($redis, $pattern, $chan, $msg)
    // {
    //     echo "Pattern: $pattern\n";
    //     echo "Channel: $chan\n";
    //     echo "Payload: $msg\n\n";
    //     //keyCallback为订阅事件后的回调函数，这里写业务处理逻辑，
    //     //比如前面提到的商品不支付自动撤单，这里就可以根据订单id,来实现自动撤单
    // }




}