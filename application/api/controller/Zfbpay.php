<?php
/**
 * Created by PhpStorm.
 * User: 75763
 * Date: 2018/12/15
 * Time: 19:53
 */

namespace app\api\controller;

use think\Db;
use think\Controller;
use think\Request;
use app\common\model\OrderModel;
use app\common\model\SystemConfigModel;
use tool\Log;

class Zfbpay extends Controller
{

    public function zfbscan(Request $request)
    {
        $message = $request->param();
        //订单号有误
        if (!isset($message['orderNo']) || empty($message['orderNo'])) {
            echo "订单号有误！";
            exit;
        }
        try {

            $orderModel = new OrderModel();
            $orderData = $orderModel
                ->where('order_me', '=', $message['orderNo'])
                ->where('order_status', '=', 4)
                ->find();
            if (empty($orderData)) {
                echo "请重新下单";
                exit;
            }

            //计算倒计时
//            $now = time();
//            $orderPayLimitTime = SystemConfigModel::getPayLimitTime();
//            $orderPayLimitTime = $orderPayLimitTime - 60;
//            $endTime = $orderData['add_time'] + $orderPayLimitTime;
//            $countdownTime = $endTime - $now;
//            if ($countdownTime < 0) {
//                echo "订单超时，请重新下单！";
//                exit;
//            }

            //修改订单收款ip
            $ip = $request->ip();
            $updateData['show_order_ip'] = $ip;
            $orderModel->where('order_me', '=', $message['orderNo'])->update($updateData);

            //展示金额
            $this->assign('payableAmountShow', $orderData['payable_amount']);
            $this->assign('pay_name', $orderData['pay_name']);
            $payUrl = '"' . $orderData['qr_url'] . '"';
            $this->assign('orderUrl', $payUrl);
            $this->assign('order_me', $orderData['order_me']);
            $this->assign('orderNo', $message['orderNo']);
            return $this->fetch();
        } catch (\Exception $e) {
            $message = $request->param();
            $msg = $e->getMessage() . "" . $e->getFile() . " " . $e->getLine();
            logs(json_encode(['message' => $message, 'msg' => $msg]), 'zklindex_exception');
            return apiJsonReturn1("444", "订单有误，请重新下单！");
        }
    }

    /**
     * 支付宝  当前
     * @param Request $request
     * @return bool|mixed
     */
    public function index(Request $request)
    {
        $message = $request->param();
        //订单号有误
        if (!isset($message['orderNo']) || empty($message['orderNo'])) {
            echo "订单号有误！";
            exit;
        }
        try {
            $orderModel = new OrderModel();
            $orderData = $orderModel
                ->where('order_me', '=', $message['orderNo'])
                ->where('order_status', '=', 4)
                ->find();
            if (empty($orderData)) {
                echo "请重新下单";
                exit;
            }

            //计算倒计时
//            $now = time();
//            $orderPayLimitTime = SystemConfigModel::getPayLimitTime();
//            $orderPayLimitTime = $orderPayLimitTime - 60;
//            $endTime = $orderData['add_time'] + $orderPayLimitTime;
//            $countdownTime = $endTime - $now;
//            if ($countdownTime < 0) {
//                echo "订单超时，请重新下单！";
//                exit;
//            }

            //修改订单收款ip
            $ip = $request->ip();
            $updateData['show_order_ip'] = $ip;
            $orderModel->where('order_me', '=', $message['orderNo'])->update($updateData);

            //展示金额
            $this->assign('payableAmountShow', $orderData['payable_amount']);
            $this->assign('pay_name', $orderData['pay_name']);
            $payUrl = '"' . $orderData['qr_url'] . '"';
            $this->assign('orderUrl', $payUrl);
            $baseurl = request()->root(true);
            $orderUrl = $baseurl . "/api/zfbpay?addname";

            $this->assign('action', $payUrl);
            $this->assign('order_me', $orderData['order_me']);
            $this->assign('orderNo', $message['orderNo']);
            return $this->fetch();
        } catch (\Exception $exception) {
            logs(json_encode(['message' => $message, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'order_index_exception');
            return apiJsonReturn('20009', "订单页面异常，请联系客服" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['message' => $message, 'file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'order_index_error');
            return apiJsonReturn('20099', "订单页面错误，请联系客服" . $error->getMessage());
        }

    }

    public function addName()
    {
        if (request()->isPost()) {

            $param = input('post.');
            var_dump($param);
            exit;
//            $orderModel = new OrderModel();

            if (!isset($param['order_me']) || empty($param['order_me'])) {
                return ['code' => -1, 'data' => '', 'msg' => "订单有有误！"];
            }
            if (!isset($param['pay_name']) || empty($param['pay_name'])) {
                return ['code' => -2, 'data' => '', 'msg' => "请输入付款人姓名！"];
            }
            $isChinses = isChinese($param['pay_name']);
            if (!$isChinses) {
                return json(modelReMsg(-1, '', '请输入正确的姓名！'));

            }
            $order = new OrderModel();
            $has = $order->where('order_me', $param['order_me'])
                ->find();
            if (empty($has)) {
                return ['code' => -2, 'data' => '', 'msg' => "请重新下单！"];
            }
            if (!empty($has['pay_name'])) {
                return ['code' => -3, 'data' => '', 'msg' => "请重新下单！"];
            }
            $where['order_me'] = $param['order_me'];
            $update['order_me'] = $param['order_me'];
            $res = $order->where($where)->update($update);
            if (!$res) {
                return ['code' => -4, 'data' => '', 'msg' => "请重新下单！"];
            }
            Log::info(request()->ip . "||order_me:" . $param['order_me'] . "||update payname||(:" . $param['pay_name'] . ")");
            return json(modelReMsg(0, '', '更新成功！'));
        } else {
            return json(modelReMsg(0, '', '更新成功！'));
        }
    }


}
