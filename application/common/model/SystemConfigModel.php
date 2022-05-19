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

    /**
     * 订单冻结期
     * @return int
     */
    public static function getOrderLockTime()
    {
        try {
            $where[] = ["configName", "=", "orderLockTime"];
            $where[] = ["status", "=", 1];
            $config = Db::table('bsa_system_config')
                ->where($where)
                ->find();
            if (isset($config['configContent']) && !empty($config['configContent'])) {
                return (int)$config['configContent'];
            }
            return 900;
        } catch (\Exception $exception) {
            return 900;
        } catch (\Error $error) {
            return 900;
        }
    }

    /**
     * 获取订单自动查询时间(第三次-第二次间隔)
     * @return int
     */
    public static function getAutoCheckOrderTime()
    {
        try {
//
//            $where[] = ['write_off_sign', '=', input('param.write_off_sign')];
            $where[] = ["configName", "=", "autoCheckOrderTime"];
            $where[] = ["status", "=", 1];
            $config = Db::table('bsa_system_config')
                ->where($where)
                ->find();
//            var_dump($config);exit;
            if (isset($config['configContent']) && !empty($config['configContent'])) {
                return (int)$config['configContent'];
            }
            return 300;
        } catch (\Exception $exception) {
            return 300;
        } catch (\Error $error) {
            return 300;
        }
    }    /**
     * 获取订单自动查询时间(第三次-第二次间隔)
     * @return int
     */
    public static function getOrderShowTime()
    {
        try {
//            $where[] = ['write_off_sign', '=', input('param.write_off_sign')];
            $where[] = ["configName", "=", "orderShowTime"];
            $where[] = ["status", "=", 1];
            $config = Db::table('bsa_system_config')
                ->where($where)
                ->find();
//            var_dump($config);exit;
            if (isset($config['configContent']) && !empty($config['configContent'])) {
                return (int)$config['configContent'];
            }
            return 180;
        } catch (\Exception $exception) {
            return 180;
        } catch (\Error $error) {
            return 180;
        }
    }
}
