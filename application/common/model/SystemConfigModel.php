<?php
/**
 * Created by PhpStorm.
 * User: 75763
 * Date: 2018/12/26
 * Time: 22:59
 */

namespace app\common\model;

use think\Model;
use think\Db;

class SystemConfigModel extends Model
{
    /**
     * 获取查询订单时间start
     * @return int
     */
    public static function getPayLimitTime()
    {
        return 180;
    }

    /**
     * 获取查询订单时间start
     * @return int
     */
    public static function getDouyinPayLimitTime()
    {
        return 900;
    }

    /**
     * 几分钟内的话单可以可以下单
     * @return int
     */
    public static function getTorderLimitTime()
    {
        return 170;
    }

    /**
     * 几分钟内的话单可以可以下单
     * @return int
     */
    public static function getTorderPrepareLimitTime()
    {
        return 300;
    }

    /**
     * 获取查询订单时间start
     * @return int
     */
    public static function LimitTime()
    {
        return 900;
    }

    /**
     * 获取自动停用金额 getDisableDeviceLimitMoney  默认50000
     * @return int|mixed
     */
    public static function getDisableDeviceLimitMoney()
    {
        try {
            $db = new Db();
            $orderPayLimitTimeStart = $db::table('s_system_config')->where('config_name', '=', 'disable_device_limit_money')->find()['config_data'];
            if ($orderPayLimitTimeStart) {
                return $orderPayLimitTimeStart;
            }
            return 50000;
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'getDisableDeviceLimitMoney_exception');
            return 50000;
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'getDisableDeviceLimitMoney_error');
            return 50000;
        }
    }
}
