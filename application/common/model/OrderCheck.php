<?php

namespace app\common\model;

use app\admin\model\CookieModel;
use app\api\model\OrderLog;
use app\api\validate\OrderinfoValidate;
use think\Db;
use think\facade\Log;
use think\Model;
use app\common\Redis;

class OrderCheck extends Model
{
    //
    public function insetOrderList($data)
    {
        $redis = new Redis();
        $rPushOrder = $redis->rpush("checkPhoneBalanceList",json_encode($data),0);
    }
}