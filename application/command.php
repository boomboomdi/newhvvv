<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
//    'app\shell\TimeouttorderNotify',
    'app\shell\Timedevice',  //定时解锁超时设备
    'app\shell\Prepareorder',  //预先生成
    'app\shell\Timecheckdouyinhuadan',  //支付限制话单回调
    'app\shell\Timecheckdouyin',  //查单回调
    'app\shell\DestroytorderUrl',  //定时销毁已拉单拉单、超时、未匹配订单
];
