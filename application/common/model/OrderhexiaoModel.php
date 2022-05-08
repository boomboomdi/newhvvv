<?php

namespace app\common\model;

use app\admin\model\CookieModel;
use app\api\model\OrderLog;
use app\api\validate\OrderinfoValidate;
use think\Db;
use think\facade\Log;
use think\Model;

class OrderhexiaoModel extends Model
{
    protected $table = 'bsa_order_hexiao';


    /**
     * 增加推单
     * @param $where
     * @param $addParam
     * @return array
     */
    public function addOrder($where, $addParam)
    {
        try {
            $has = $this->where($where)->findOrEmpty()->toArray();
            if (!empty($has)) {
                return modelReMsg(-2, '', '此核销单已经存在');
            }
            $this->insert($addParam);
        } catch (\Exception $e) {

            return modelReMsg(-1, '', $e->getMessage());
        }

        return modelReMsg(0, "", '添加推单成功');
    }

    /**
     * //接口地址：http://127.0.0.1:23943/queryBlance
     * //post请求参数：
     * //{"phone":"13283544163"}
     * //成功返回：
     * //{'code': 0, 'msg': 'SUCCESS', 'data': {'phone': '13283544163', 'amount': 469.19}, 'sign': '488864C0AB51AEA0AF551074446FBCEC'}
     * //失败返回：
     * //{"code":9999,"msg":"余额获取失败","data":null,"sign":null}
     * 查询手机余额
     * @param $checkParam --订单id  查询单号（四方）
     * @param $orderNo --核销order_no
     * @return array
     */
    public function checkPhoneAmount($checkParam, $orderNo)
    {
        try {
            $checkStartTime = date('Y-m-d H:i:s', time());
            $notifyResult = curlPostJson("http://127.0.0.1:23943/queryBlance", $checkParam);
            $notifyResult = json_decode($notifyResult, true);
            logs(json_encode([
                'writeOrderNo' => $orderNo,  //核销order_no
                'param' => $checkParam,
                "startTime" => $checkStartTime,
                "endTime" => date("Y-m-d H:i:s", time()),
                "checkAmountResult" => $notifyResult
            ]), 'curlCheckPhoneAmount_log');
            //查询成功

//            $notifyResultData = json_decode($notifyResult['data'], true);
            //{"code":0,"msg":"SUCCESS","data":{"phone":"13333338889","amount":469.19},"sign":"488864C0AB51AEA0AF551074446FBCEC"}
            if (!isset($notifyResult['code']) || $notifyResult['code'] != 0) {
                return modelReMsg(-1, "", $notifyResult['msg']);
            }

            return modelReMsg(0, $notifyResult['data']['amount'], '查询成功！');
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]),
                'checkPhoneAmountException');
            return modelReMsg(-11, '', $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]),
                'checkPhoneAmountError');
            return modelReMsg(-22, "", $error->getMessage());
        }
    }

    /**
     * {
     * "write_off_sign":"lisi", //string
     * "order_no":"e10adc3949ba59abbe56e057f20f883e",  //推单单号|string
     * "account":"13388888888",      //充值账号|string
     * "total_amount":"1.00",        //金额|float保留两位
     * "success_amount":"1.00",        //充值金额|float保留两位
     * "pay_time":"Y-m-d H:i:s",    //支付时间|2022-4-1 12:21:12
     * "sign":"" |string
     * }
     * 本地更新  bsa_order    bsa_order_hexiao
     * @param $orderHxData
     * @param $amount
     * @param $orderStatus
     * @return array
     */
    public function orderLocalUpdate($orderHxData, $orderStatus = 1, $amount = "")
    {

        $db = new Db();
        $db::startTrans();
        try {
            //更新核销表  start
            $orderWhere['order_me'] = $orderHxData['order_me'];
//            $orderWhere['account'] = $orderHxData['account'];
            $payTime = time();
            $lockHxOrderRes = $db::table("bsa_order_hexiao")->where('id', '=', $orderHxData['id'])->lock(true)->find();
            if (!$lockHxOrderRes) {
                $db::rollback();
                return modelReMsg(-1, "", "update fail rollback");
            }
            logs(json_encode(['file' => $orderHxData,
                'time' => date("Y-m-d H:i:s", time()),
                'orderStatus' => $orderStatus
            ]), 'orderLocalUpdate');
            $amount = $orderHxData['order_amount'];
            $updateHXData['pay_amount'] = (float)$amount;
            $updateHXData['pay_time'] = $payTime;
            $updateHXData['status'] = 2;
            $updateHXData['pay_status'] = 1;
            $updateHXRes = $db::table("bsa_order_hexiao")->where($orderWhere)
                ->update($updateHXData);
            if (!$updateHXRes) {
                $db::rollback();
                return modelReMsg(-2, "", "update fail rollback");
            }
            //更新核销表  end

            //更新订单表
            $orderData = $db::table('bsa_order')->where($orderWhere)->find();   //订单
            $lockOrderRes = $db::table('bsa_order')->where('id', '=', $orderData['id'])->lock(true)->find();
            if (!$lockOrderRes) {
                $db::rollback();
                return modelReMsg(-3, "", "update lock order fail rollback");
            }

            $updateOrderData['actual_amount'] = (float)$amount;
            $updateOrderData['pay_status'] = 1;
            $updateOrderData['pay_time'] = $payTime;
            $updateOrderData['order_status'] = 1;
            $updateOrderData['check_status'] = 2;
            $updateOrderRes = $db::table('bsa_order')->where($orderWhere)
                ->update($updateOrderData);
            logs(json_encode([
                'orderWhere' => $orderWhere,
                'updateOrderData' => $updateOrderData,
                'updateOrderRes' => $updateOrderRes,
                'sql' => $db::table('bsa_order')->getLastSql()
            ]), 'updateOrderRes');
            if (!$updateOrderRes) {
                $db::rollback();
                return modelReMsg(-4, "", "update order fail rollback");
            }
            $db::commit();
            return modelReMsg(0, "", "更新成功");
        } catch (\Exception $exception) {
            $db::rollback();
            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderLocalUpdateException');
            return modelReMsg(-11, "", "回调失败" . $exception->getMessage());
        } catch (\Error $error) {
            $db::rollback();
            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()
            ]), 'orderLocalUpdateError');
            return modelReMsg(-22, "", "回调失败" . $error->getMessage());
        }
    }

    /**
     *
     * 获取可用付款抖音话单支付链接
     * @param $where
     * @return array
     */
    public function getUseHxOrder($order, $getTimes = 1)
    {
        $msg = "失败！";
        $db = new Db();
        $db::startTrans();
        try {
            $hxOrderInfo = $this
                ->where('order_amount', '=', $order['amount'])
                ->where('order_me', '=', null)
                ->where('status', '=', 0)
                ->where('order_status', '=', 0)
                ->where('order_limit_time', '<', time())
                ->where('check_status', '=', 0)  //是否查单使用中
                ->order("add_time asc")
                ->lock(true)
                ->find();
//            logs(json_encode(['action' => 'getUseHxOrder', 'orderNo' => $order['order_no'], 'hxOrderInfo' => $hxOrderInfo]), 'getUseHxOrder_log');

            if (!$hxOrderInfo) {
                $db::rollback();
                return modelReMsg(-1, '', '无可用下单！');
            }
            $orderWhere['id'] = $hxOrderInfo['id'];
            $checking['order_status'] = 1;  //使用中
            $checking['check_status'] = 1;   //查询余额中
            $this->where($orderWhere)->update($checking);


            $checkParam['phone'] = $hxOrderInfo['account'];
            $checkParam['order_no'] = $hxOrderInfo['account'];
            $checkParam['action'] = 'first';
            $checkRes = $this->checkPhoneAmount($checkParam, $hxOrderInfo['order_no']);
            if ($checkRes['code'] != 0) {
                $db::rollback();
                return modelReMsg(-2, '', '下单频繁，请稍后再下-2！');
            }
            //查询成功更新余额order_hexiao $order order_hexiao
            $orderWhere['id'] = $hxOrderInfo['id'];
            $updateMatch['last_check_amount'] = (float)$checkRes['data'];
            $updateMatch['check_status'] = 0;
            $updateMatch['status'] = 1;   //使用中
            $updateMatch['last_check_time'] = time();  //上次查询余额时间
            $updateMatch['use_time'] = time();   //使用时间
            $updateMatch['use_times'] = $hxOrderInfo['use_times'] + 1;   //使用次数+1
            $updateMatch['last_use_time'] = time();
            $updateMatch['order_limit_time'] = time() + 3600;  //匹配成功后锁定3600s 后没支付可以重新解锁匹配
            $updateMatch['order_status'] = 1;
            $updateMatch['order_me'] = $order['order_me'];
            $updateMatch['order_desc'] = "匹配成功！当前余额:" . $checkRes['data'];

            $updateMatchRes = $this->where($orderWhere)->update($updateMatch);
            if (!$updateMatchRes) {
                $db::rollback();
                logs(json_encode(['action' => 'getUseHxOrderUpdateMatch',
                    'orderWhere' => $hxOrderInfo,
                    'updateMatch' => $updateMatch,
                    'updateMatchRes' => $updateMatchRes,
                ]), 'getUseHxOrder_log');

                return modelReMsg(-3, '', '下单频繁，请稍后再下-3！');
            }

            $db::commit();
            $hxOrderInfo['last_check_amount'] = $checkRes['data'];
            return modelReMsg(0, $hxOrderInfo, "getUseTOrderNew_res预拉失败");

        } catch (\Exception $exception) {
            $db::rollback();
            logs(json_encode(['orderNo' => $order['order_no'],
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()]), 'getUseHxOrderException');
            return modelReMsg(-11, '', $exception->getMessage());
        } catch (\Error $error) {
            $db::rollback();
            logs(json_encode(['orderNo' => $order['order_no'],
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'getUseHxOrderError');
            return modelReMsg(-11, '', $error->getMessage());
        }

    }


    /**
     * {
     * "write_off_sign":"lisi", //string
     * "order_no":"e10adc3949ba59abbe56e057f20f883e",  //推单单号|string
     * "account":"13388888888",      //充值账号|string
     * "order_amount":"1.00",        //金额|float保留两位
     * "success_amount":"1.00",        //充值金额|float保留两位
     * "pay_time":"Y-m-d H:i:s",    //支付时间|2022-4-1 12:21:12
     * "sign":"" |string
     * }
     * 回调核销后台
     * @param $tOrderData
     * @return void
     */
    public function orderNotifyToWriteOff($orderHXData, $orderStatus = 1)
    {
        $db = new Db();
        $db::startTrans();
        try {
            $db = new Db();
            $writeWhere['write_off_sign'] = $orderHXData['write_off_sign'];
            $writeOff = $db::table("bsa_write_off")->where($writeWhere)->find();
            if (empty($writeOff)) {
                $db::rollback();
                return modelReMsg(-1, "", "notify hexiao lock fail");
            }
//            $orderHXWhere['order_no'] = (string)$orderHXData['order_no'];
            $lockOrderHXData = $db::table("bsa_order_hexiao")->where('id', $orderHXData['id'])->lock(true)->find();
            if (!$lockOrderHXData) {
                $db::rollback();
                return modelReMsg(-2, "", "notify hexiao lock fail");
            }

            $notifyParam['write_off_sign'] = $orderHXData['write_off_sign'];
            $notifyParam['order_no'] = $orderHXData['order_no'];
            $notifyParam['account'] = $orderHXData['account'];
            $notifyParam['order_type'] = $orderHXData['order_type'];
            $notifyParam['order_amount'] = $orderHXData['order_amount'];
            $notifyParam['pay_amount'] = $orderHXData['pay_amount'];
            $notifyParam['pay_status'] = $orderHXData['pay_status'];
            if ($orderHXData['pay_time'] != 0) {
                $notifyParam['time'] = $orderHXData['pay_time'];
            } else {
                $notifyParam['time'] = time();
            }
            $md5Sting = $notifyParam['write_off_sign'] . $notifyParam['order_no'] . $notifyParam['account'] . $notifyParam['pay_status'] . $notifyParam['order_amount'] . $notifyParam['pay_amount'] . $notifyParam['time'] . $writeOff['token'];
            $notifyParam['sign'] = md5($md5Sting);
            $startTime = date("Y-m-d H:i:s", time());
            //回调核销  已经收到款项
            $notifyResult = curlPostJson($orderHXData['notify_url'], $notifyParam);
            logs(json_encode([
                "startTime" => $startTime,
                "endTime" => date("Y-m-d H:i:s", time()),
                'notifyParam' => $notifyParam,
                "paramAddTime" => date("Y-m-d H:i:s", $orderHXData['add_time']),
                "notifyResult" => $notifyResult
            ]), 'curlPostJsonToWriteOff_log');
            $notifyResultLog = "第" . ($orderHXData['notify_times'] + 1) . "次回调:" . json_encode($notifyResult) . "(" . date("Y-m-d H:i:s") . ")";

            //通知结果不为success
            if ($notifyResult != "success") {
                $db::rollback();
                $db::table('bsa_order_hexiao')->where('id', $orderHXData['id'])
                    ->update([
                        'notify_time' => time(),
                        'notify_times' => $orderHXData['notify_times'] + 1,
                        'notify_result' => $notifyResultLog,
                        'order_desc' => "回调失败:" . $notifyResult
                    ]);
                return modelReMsg(-3, "", "回调结果失败！");

            }
            $db::table('bsa_order_hexiao')->where('id', $orderHXData['id'])
                ->update([
                    'notify_time' => time(),
                    'notify_status' => 1,
                    'notify_times' => $orderHXData['notify_times'] + 1,
                    'notify_result' => $notifyResultLog,
                    'order_desc' => "回调成功:" . $notifyResult
                ]);
            $db::commit();
            return modelReMsg(0, "", json_encode($notifyResult));
        } catch (\Exception $exception) {
            $db::rollback();
            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderNotifyToWriteOffException');
            return modelReMsg(-11, "", "回调失败" . $exception->getMessage());
        } catch (\Error $error) {
            $db::rollback();
            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()
            ]), 'orderNotifyToWriteOffError');
            return modelReMsg(-22, "", "回调失败" . $error->getMessage());

        }
    }

    /**
     * 支付超时订单修改
     * @param $where
     * @param $updateData
     * @return array
     */
    public function localUpdateHXOrder($where, $updateData)
    {
        $this->startTrans();
        try {
            $orderHxInfo = $this->where($where)->lock(true)->find();
            if (!$orderHxInfo) {
                $this->rollback();
                logs(json_encode([
                    'orderWhere' => $where,
                    'updateData' => $updateData,
                    'orderHxInfo' => $orderHxInfo
                ]), 'localUpdateHXOrderFail_log');
                return modelReMsg(-1, "", "更新失败!");
            }
            $updateData['order_desc'] = "订单冻结.等待第" . $orderHxInfo['use_times'] . "使用!";
            $updateRes = $this->where($where)->update($updateData);
            if (!$updateRes) {
                $this->rollback();
                logs(json_encode([
                    'orderWhere' => $where,
                    'updateData' => $updateData,
                    'updateRes' => $updateRes
                ]), 'localUpdateHXOrderFail_log');
                return modelReMsg(-2, "", "更新失败");
            }
//            logs(json_encode([
//                'orderWhere' => $where,
//                'updateData' => $updateData,
//                'updateRes' => $updateRes
//            ]), 'localhostUpdateHxOrder');

            $this->commit();
            return modelReMsg(0, "", "更新成功");

        } catch (\Exception $exception) {
            $this->rollback();
            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'localUpdateHXOrderException');
            return modelReMsg(-11, "", $exception->getMessage());
        } catch (\Error $error) {
            $this->rollback();
            logs(json_encode([
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()
            ]), 'localUpdateHXOrderError');
            return modelReMsg(-22, "", $error->getMessage());
        }
    }
}