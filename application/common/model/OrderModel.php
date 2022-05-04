<?php

namespace app\common\model;

use app\api\model\OrderLog;
use app\api\validate\OrderinfoValidate;
use think\Db;
use think\facade\Log;
use think\Model;

class OrderModel extends Model
{
    protected $table = 'bsa_order';

    /**
     * 获取订单
     * @param $limit
     * @param $where
     * @return array
     */
    public function getOrders($limit, $where)
    {
        $prefix = config('database.prefix');
        try {
            $res = $this->field($prefix . 'order.*')->where($where)
                ->order('order_no', 'desc')->paginate($limit);

        } catch (\Exception $e) {

            return modelReMsg(-1, '', $e->getMessage());
        }
        return modelReMsg(0, $res, 'ok');
    }

    /**
     * 增加订单
     * @param $Order
     * @return array
     */
    public function addOrder($Order)
    {
        try {
            $has = $this->where('order_no', $Order['order_no'])->findOrEmpty()->toArray();
            if (!empty($has)) {
                return modelReMsg(-2, '', '订单号已经存在');
            }
            $this->insert($Order);
        } catch (\Exception $e) {

            return modelReMsg(-1, '', $e->getMessage());
        }

        return modelReMsg(0, '', '添加订单成功');
    }

    /**
     * 订单回调第一步:匹配订单
     * @param $notifyParam
     * @return void
     */
    public function orderMatch($notifyParam)
    {
        $where = [];
        try {
            if (isset($notifyParam['notify_pay_name']) && !empty($notifyParam['notify_pay_name'])) {
                $where[] = ['notify_pay_name', $notifyParam['notify_pay_name']];
            }

            $where[] = ['account', $notifyParam['account']];
            $where[] = ['order_status', 4];
            $where[] = ['amount', $notifyParam['amount']];
            $where[] = ['operate_time', 'between', [time() - 3000, time()]];

            $info = $this->where($where)->find();
            if (empty($info)) {
                return modelReMsg(-2, '', '未匹配到订单');
            }
        } catch (\Exception $e) {
            return modelReMsg(-1, '', $e->getMessage());
        }
        return modelReMsg(0, $info, '匹配订单成功');
    }

    /**
     * 订单回调: 通知商户
     * @return void
     */
    public function notifyToCenter()
    {

    }


    /**
     * 订单回调 通道/手动回调 总入口
     * @param $orderData
     * @param $status 1、自动回调 2、手动回调
     * @return array|void
     */
    public function orderNotify($orderData, $status = 1)
    {

        try {
            //更改订单状态 order
            //1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。6、回调中（还未通知商户）
            $orderWhere['order_me'] = $orderData['order_me'];
            $orderWhere['order_pay'] = $orderData['order_pay'];
//            $order = Db::table('bsa_order')->where($orderWhere)->find();
            //4和6是可回调状态
            if ($orderData['order_status'] != 6 || $orderData['order_status'] != 4) {
                $returnMsg['code'] = 1003;
                $returnMsg['msg'] = "不可回调状态!";
                $returnMsg['data'] = $orderData;
            }

            $orderUpdate['order_status'] = 6;

            if ($orderData['order_status'] == 6) { //手动回调 本地更新未通知四方
                $orderNotifyForMerchantRes = $this->orderNotifyForMerchant($orderData, 2);
                if ($orderNotifyForMerchantRes['code'] > 0) {
                    return $orderNotifyForMerchantRes;
                }
            }
            $orderUpdate['order_status'] = 6;
            $orderUpdate['update_time'] = time();
            $orderUpdate['pay_time'] = time();
            $orderUpdate['actual_amount'] = (float)$orderData['amount'];
            Db::table('bsa_order')->where($orderWhere)->update($orderUpdate);
            $orderData = Db::table('bsa_order')->where($orderWhere)->find();

            //更改商户余额 merchant
            $merchantWhere['merchant_sign'] = $orderData['merchant_sign'];
            $merchant = Db::table('bsa_merchant')->where($merchantWhere)->find();
            Db::table('bsa_merchant')->where($merchantWhere)
                ->update([
                    "amount" => $merchant["amount"] + $orderData['amount']
                ]);
            if (!empty($orderData['write_off_sign'])) {
                $writeOffWhere['studio_sign'] = $orderData['write_off_sign'];
                $writeOff = Db::table('bsa_write_off')->where($writeOffWhere)->find();
                Db::table('bsa_write_off')->where($writeOffWhere)
                    ->update([
                        "amount" => $writeOff['amount'] + $orderData['amount']
                    ]);
            }

            $notifyRes = $this->orderNotifyForMerchant($orderData, $status);

            if ($notifyRes['code'] != 1000) {
                return modelReMsg(-2, '', $notifyRes['msg']);
            }
            return modelReMsg(1000, '', $notifyRes['msg']);
        } catch (\Exception $exception) {
            logs(json_encode(['orderData' => $orderData, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'orderNotify_exception');
            return modelReMsg(-2, '', $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['orderData' => $orderData, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'orderNotify_error');
            return modelReMsg(-3, '', $error->getMessage());
        }
    }


    /**
     * 支付成功（通知商户）
     * @param $data
     * @param $status
     * @return void
     * @todo
     */
    public function orderNotifyForMerchant($data, $status = 1)
    {
        $db = new Db();
        $db::startTrans();
        try {
            //$status 决定order_status 是手动回调还是自动完成且回调
            //参与回调参数
            $callbackData['merchant_sign'] = $data['merchant_sign'];
            $callbackData['order_no'] = (string)$data['order_no'];
            $callbackData['order_status'] = 1;
            $callbackData['order_pay'] = $data['order_me'];
            $callbackData['payment'] = $data['payment'];
            $callbackData['amount'] = $data['amount'];
            $callbackData['actual_amount'] = $data['actual_amount'];
            $callbackData['pay_time'] = date("Y-m-d H:i:s", $data['pay_time']);
            $validate = new OrderinfoValidate();

            //请求参数不完整
            if (!$validate->scene('notify')->check($callbackData)) {
                logs(json_encode(['callbackData' => $callbackData, 'status' => $status, 'errorMessage' => $validate->getError()]), 'orderNotifyForMerchant_checkfail');
                $returnMsg['code'] = 1002;
                $returnMsg['msg'] = "回调参数有误!";
                $returnMsg['data'] = $validate->getError();
                return $returnMsg;
            }
            $merchantWhere['merchant_sign'] = $data['merchant_sign'];
            $token = Db::table("bsa_merchant")->where($merchantWhere)->find()['token'];
            logs(json_encode(['callbackData' => $data, 'notify_url' => $data['notify_url']]), 'curlPostForMerchant_log1');

            $returnMsg = array();
            $doMd5String = $callbackData['merchant_sign'] . $callbackData['order_no'] . $callbackData['amount'] . $callbackData['actual_amount'] . $callbackData['pay_time'] . $token;
            $callbackData['sign'] = md5($doMd5String);
            //回调处理
            $notifyResult = curlPostJson($data['notify_url'], $callbackData);
            logs(json_encode(['callbackData' => $callbackData, 'notify_url' => $data['notify_url'], 'notifyResult' => $notifyResult]), 'curlPostForMerchant_log');
//            $result = json_decode($notifyResult, true);
            //通知失败

            if ($data) {
                $orderWhere['order_no'] = $callbackData['order_no'];  //orderData
                if ($notifyResult != "success") {
                    $updateData['order_desc'] = "回调失败|" . json_encode($notifyResult);
                    $updateRes = Db::table('bsa_order')->where($orderWhere)->update($updateData);
                    if (!$updateRes) {
                        $db::rollback();
                        $returnMsg['code'] = 3000;
                        $returnMsg['msg'] = "回调失败!请联系管理员";
                        $returnMsg['data'] = json_encode($notifyResult);
                        return $returnMsg;
                    }
                    $db::commit();
                    $returnMsg['code'] = 1000;
                    $returnMsg['msg'] = "回调失败!";
                    $returnMsg['data'] = json_encode($notifyResult);

                    return $returnMsg;
                }
                //如果是手动回调
                $orderWhere['order_no'] = $callbackData['order_no'];
                if ($status == 2) {
                    $updateData['order_status'] = 5;
                    $updateData['status'] = 1;
                    $updateData['update_time'] = time();
                    $updateData['order_desc'] = "手动回调成功|" . json_encode($notifyResult);
                    $updateRes = Db::table('bsa_order')->where($orderWhere)->update($updateData);
                    if (!$updateRes) {
                        $returnMsg['code'] = 3000;
                        $returnMsg['msg'] = "手动回调失败!请联系管理员";
                        $returnMsg['data'] = json_encode($notifyResult);
                        $db::rollback();
                        return $returnMsg;
                    }

                    $db::commit();
                    $returnMsg['code'] = 1000;
                    $returnMsg['msg'] = "手动回调成功!";
                    $returnMsg['data'] = json_encode($notifyResult);
                    return $returnMsg;
                }
                if ($status == 1) {
                    $orderUpdate['order_status'] = 1;
                    $orderUpdate['update_time'] = time();
                    $orderUpdate['status'] = 1;
                    $orderUpdate['order_desc'] = "回调成功|" . json_encode($notifyResult);
                    $updateRes = Db::table('bsa_order')->where($orderWhere)->update($orderUpdate);

                    if (!$updateRes) {
                        $db::rollback();
                        $returnMsg['code'] = 4000;
                        $returnMsg['msg'] = "回调失败!请联系管理员";
                        $returnMsg['data'] = json_encode($notifyResult);
                        return $returnMsg;
                    }

                    $db::commit();
                    $returnMsg['code'] = 1000;
                    $returnMsg['msg'] = "回调成功!";
                    $returnMsg['data'] = json_encode($notifyResult);
                    return $returnMsg;
                }
            }
            $returnMsg['code'] = 4000;
            $returnMsg['msg'] = "回调失败!";
            $returnMsg['data'] = json_encode($notifyResult);
            return $returnMsg;

        } catch (\Exception $exception) {
            $db::rollback();
            logs(json_encode(['data' => $data, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'orderNotifyForMerchant_exception');
            return modelReMsg(-2, '', $exception->getMessage());
        } catch (\Error $error) {
            $db::rollback();
            logs(json_encode(['data' => $data, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'orderNotifyForMerchant_error');
            return modelReMsg(-3, '', $error->getMessage());
        }

    }


}