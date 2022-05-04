<?php

namespace app\api\controller;

use app\api\model\TorderModel;
use think\Db;
use think\facade\Log;
use think\Request;
use app\api\validate\OrderValidate;
use think\Controller;

class Order extends Controller
{
    /**
     * 推单接口/上传油卡
     * @apiMerchantNo               String     渠道分配的API商户编号
     * @apiMerchantOrderNo          String     API商户唯一订单号
     * @apiMerchantOrderCardNo      String     充值油卡号
     * @apiMerchantOrderAmount      int        充值金额(单位：元)
     * @apiMerchantOrderType        String     充值类型：（1001H5：石油卡；1002H5：石化卡）
     * @apiMerchantOrderNotifyUrl   String     异步回调地址（不需要转义符），接收充值结果
     * @apiMerchantOrderDate        String     订单过期时间（格式：yyyy-MM-dd hh:mm:ss
     * @apiMerchantOrderExpireDate  String     订单过期时间（格式：yyyy-MM-dd hh:mm:ss
     * @sign                        String     签名
     * {
     * "apiMerchantNo": "76153933",
     * "apiMerchantOrderNo": "TA1626893330347",
     * "apiMerchantOrderCardNo": "1000111100014749155",
     * "apiMerchantOrderAmount": 500,
     * "apiMerchantOrderDate": "2021-07-22 02:48:50",
     * "apiMerchantOrderExpireDate": null,
     * "apiMerchantOrderType": "1002H5",
     * "apiMerchantOrderNotifyUrl": "http://127.0.0.1:9200/api/order/callback",
     * "sign": "9DF6612CA0D9CAF95702216EC3B06B57"
     * }
     * @return JSON
     *
     * code             string      推单状态码，详见推单结果状态码
     * msg              string      推单结果提示信息，详见推单结果状态码
     * orderDiscount    float       订单折扣
     * orderExpireDate  string      订单过期时间，推单时传入了则返回推单传入的，未传入则以充值方系统设置为准（格式：yyyy-MM-dd hh:mm:ss）
     *  {
     * "code": "1000",
     * "msg": "推单成功",
     * "orderDiscount": 0.95,
     * "orderExpireDate": "2021-07-22 03:08:50"
     * }
     * 1000    推单成功    推单成功
     * 1001    请求方式错误    请使用 post请求
     * 1002    请求参数不完整    会返回具体错误参数名
     * 1005    卡商商账户异常    商户编号错误或被禁用
     * 1006    无效推单金额    无效推单金额
     * 1007    签名错误    签名验证未通过
     * 1008    订单号重复    订单号重复
     * 1009    系统错误    写入数据失败或读取必要配置失败
     * 1010    无效推单类型    无效推单类型
     * 1012    推单类型和充值卡类型不匹配    石油卡9开头，石化卡1开头
     */
    public function create(Request $request)
    {
        try {
//            $method = $request->method();var_dump($method);exit;
            if ($request->isPost()) {
                $data = @file_get_contents('php://input');
                $param = json_decode($data, true);
                //                var_dump($param);exit();
                $validate = new OrderValidate();
                //请求参数不完整
                if (!$validate->check($param)) {
                    $returnMsg['code'] = 1002;
                    $returnMsg['msg'] = "参数不完整!" . $validate->getError();
                    $returnMsg['orderDiscount'] = "0.00";
                    $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                    return json_encode($returnMsg);
                }

                //卡商商账户异常  @todo

                //无效推单金额  @todo

                //商户
                $db = new Db();
                $where['merchant_sign'] = $param['apiMerchantNo'];
                $validatetmerchant = $db::table('bsa_tmerchant')->where($where)->find();
                if (empty($validatetmerchant)) {
                    $returnMsg['code'] = 2004;
                    $returnMsg['msg'] = "无效商户!";
                    $returnMsg['orderStatus'] = 0;
                    $returnMsg['officialMsg'] = "";
                    $returnMsg['amount'] = "";
                    $returnMsg['cardNo'] = "";
                    $returnMsg['orderCreateDate'] = date('Y-m-d H:i:s', time());
                    $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                    $returnMsg['orderDiscount'] = "0.00";
                    return json_encode($returnMsg);
                }

                //$sign 1.	将接口API中所有标明参与签名的【非空参数】按照ASCII码从小到大排序，拼接成形如：param1=a&param2=b&param3=c
                //      2.	在末尾拼接渠道分配的密钥：&key=xxxxxxx。拼接后格式：param1=a&param2=b&param3=c&key=xxxxxxx
                //      3.	对拼接后的字符串进行MD5并转成大写，即得到签名sign。
                //签名错误
                if (empty($param['apiMerchantOrderExpireDate'])) {
                    unset($param['apiMerchantOrderExpireDate']);
                }
                $orderSign = $param['sign'];
                unset($param['sign']);
                ksort($param);
                $sign = urldecode(http_build_query($param));
                $returnMsg = array();
                if ($orderSign != strtoupper(md5($sign . "&key=" . $validatetmerchant['token']))) {
                    $returnMsg['code'] = 1007;
                    $returnMsg['msg'] = "签名错误!";
//                    $returnMsg['msg'] = "签名错误!".strtoupper(md5($sign . "&key=" . $validatetmerchant['token']));
                    $returnMsg['orderDiscount'] = "0.00";
                    $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                    return json_encode($returnMsg);
                }

                //订单号重复
                $tOrderModel = new TorderModel();
                $has = $tOrderModel->getTorderByMerchantOrderNo($param['apiMerchantOrderNo']);
                if ($has['code'] != 0) {
                    $returnMsg['code'] = 1008;
                    $returnMsg['msg'] = "订单号重复!";
                    $returnMsg['orderDiscount'] = "0.00";
                    $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                    return json_encode($returnMsg);
                }
                //无效推单类型 @todo
                //推单类型和充值卡类型不匹配@todo
                $param['apiMerchantOrderDate'] = strtotime($param['apiMerchantOrderDate']);
                $param['orderCreateDate'] = time();
                $param['apiMerchantOrderExpireDate'] = empty($param['apiMerchantOrderExpireDate']) ? 0 : strtotime($param['apiMerchantOrderExpireDate']);
                //系统错误
//                var_dump($param);exit;

                $param['apiMerchantNo'] = $param['apiMerchantNo'];
                $addTorder = $tOrderModel->addTorder($param);
                if ($addTorder['code'] != 0) {
                    $returnMsg['code'] = 1008;
                    $returnMsg['msg'] = $addTorder['msg'];
                    $returnMsg['orderDiscount'] = "0.00";
                    $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                    return json_encode($returnMsg);
                }
                $returnMsg['code'] = 1000;
                $returnMsg['msg'] = "推单成功!";
                $returnMsg['orderDiscount'] = "0.00";
                $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                Log::info($param, $returnMsg);
                return json_encode($returnMsg);
            } else {
                $returnMsg['code'] = 1001;
                $returnMsg['msg'] = "请求方式错误!";
                $returnMsg['orderDiscount'] = "0.00";
                $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
                return json_encode($returnMsg);
            }
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "请求方式错误!" . $e->getMessage();
            $returnMsg['orderDiscount'] = "0.00";
            $returnMsg['orderExpireDate'] = date('Y-m-d H:i:s', time());
            return json_encode($returnMsg);
        }
    }

    /**
     *  商户查单接口
     * apiMerchantNo    是    string    商户编码
     * apiMerchantOrderNo    是    string    所查订单编号
     * sign    是    string    签名
     * {
     * apiMerchantNo:76153933,
     * apiMerchantOrderNo:"TA1626885076531"
     * sign:"2151EBF75C25FA24937FF723898294FB"
     * }
     * @return JSON
     *
     * {
     * "code": "2100",
     * "msg": "查询成功",
     * "orderStatus": 0,
     * "officialMsg": "已付款",
     * "amount": 500,
     * "cardNo": "1000111100014749155",
     * "orderCreateDate": "2021-07-22 02:48:50",
     * "orderExpireDate": "2021-07-22 02:58:50",
     * "orderDiscount": 0.9500
     * }
     */
    public function status()
    {
        try {
            $data = @file_get_contents('php://input');
            $param = json_decode($data, true);
            Log::write("/n/t TOrder/status: /n/t" . json_encode($param) . "/n/t", "Log");
            //                var_dump($param);exit();
            if (!isset($param['apiMerchantNo']) || empty($param['apiMerchantNo'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantNo must be require";
                return json_encode($returnMsg);
            }
            if (!isset($param['apiMerchantOrderNo']) || empty($param['apiMerchantOrderNo'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantOrderNo must be require";
                return json_encode($returnMsg);
            }
            if (!isset($param['sign']) || empty($param['sign'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.sign must be require";
                return json_encode($returnMsg);
            }

            $db = new Db();
            $where['merchant_sign'] = $param['apiMerchantNo'];
            //验证商户
            $validatetmerchant = $db::table('bsa_tmerchant')->where($where)->find();
            if (empty($validatetmerchant)) {
                $returnMsg['code'] = 2004;
                $returnMsg['msg'] = "无效商户!";
                $returnMsg['orderStatus'] = 0;
                $returnMsg['officialMsg'] = "";
                $returnMsg['amount'] = "";
                $returnMsg['cardNo'] = "";
                $returnMsg['orderCreateDate'] = date(time());
                $returnMsg['orderExpireDate'] = date(time());
                $returnMsg['orderDiscount'] = "0.00";
                return json_encode($returnMsg);
            }
            //验签
            $orderSign = $param['sign'];
            unset($param['sign']);
            ksort($param);
            $returnMsg = array();
            if ($orderSign != strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']))) {
//                var_dump(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']);
//                var_dump(strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token'])));
//                exit;
                $returnMsg['code'] = 2101;
                $returnMsg['msg'] = "签名无效!";
                return json_encode($returnMsg);
            }
            //查询推单
            $torderWhere['apiMerchantOrderNo'] = $param['apiMerchantOrderNo'];
            $torderWhere['apiMerchantNo'] = $param['apiMerchantNo'];
//            $torderWhere['orderStatus'] = 0;
//            $torderWhere['status'] = 4;
            $tOrderModel = new TorderModel();
            $has = $tOrderModel->getTorderByWhere($torderWhere);
            if ($has['code'] != 0) {
                $returnMsg['code'] = 2102;
                $returnMsg['msg'] = "订单不存在!";
//                $returnMsg['msg'] = "订单不存在!".$db::table("bas_torder")->getLastSql();
                $returnMsg['orderDiscount'] = "0.00";
                $returnMsg['orderExpireDate'] = date(time());
                return json_encode($returnMsg);
            }
            $tOrderData = $has['data'];
            $returnMsg['code'] = 2100;
            $returnMsg['msg'] = "查单成功!";
            $tOrderData['orderStatus'] = 2;
            if ($tOrderData['orderStatus'] == 4) {
                $tOrderData['orderStatus'] = 0;
            }
            if ($tOrderData['orderStatus'] == 1 || $tOrderData['orderStatus'] == 3) {
                $tOrderData['orderStatus'] = 1;
            }
            $returnMsg['orderStatus'] = $tOrderData['orderStatus'];
            $returnMsg['officialMsg'] = $tOrderData['apiMerchantOrderOfficialMsg'];
            $returnMsg['amount'] = $tOrderData['apiMerchantOrderAmount'];
            $returnMsg['cardNo'] = $tOrderData['apiMerchantOrderCardNo'];
            $returnMsg['orderCreateDate'] = date('Y-m-d h:i:s', $tOrderData['orderCreateDate']);
            $returnMsg['orderExpireDate'] = date('Y-m-d h:i:s', $tOrderData['orderExpireDate']);
            $returnMsg['orderDiscount'] = $tOrderData['orderDiscount'];
            return json_encode($returnMsg);
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "系统错误错误!" . $e->getMessage();
            return json_encode($returnMsg);
        }
    }

    /**
     *  商户by amount 获取接口
     * amount    是    string    商户编码
     * sign    是    string    签名
     * {
     * amount:100
     * }
     * @return JSON
     *
     * {
     * "code": "2100",
     * "msg": "查询成功",
     * "orderStatus": 0,
     * "officialMsg": "已付款",
     * "amount": 500,
     * "cardNo": "1000111100014749155",
     * "orderCreateDate": "2021-07-22 02:48:50",
     * "orderExpireDate": "2021-07-22 02:58:50",
     * "orderDiscount": 0.9500
     * }
     */
    public function get(Request $request)
    {
        try {
            $data = @file_get_contents('php://input');
            $param = json_decode($data, true);
            //                var_dump($param);exit();
            $db = new Db();

            if (!isset($param['amount']) || empty($param['amount'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantOrderAmount must be require";
                return json_encode($returnMsg);
            }
            //查询推单
            $torderWhere['apiMerchantOrderAmount'] = $param['amount'];
            $tOrderModel = new TorderModel();
            $field = "apiMerchantOrderCardNo,apiMerchantOrderNo,apiMerchantOrderAmount";
            $has = $tOrderModel->getTorderForGet($torderWhere, $field);
            if ($has['code'] != 0) {
                $returnMsg['code'] = 2102;
                $returnMsg['msg'] = "无可用 no useful order!";
                return json_encode($returnMsg);
            }
            $db::startTrans();//开启事务
            $db::table('bsa_torder')->where($torderWhere)->lock();
            $updateParam['status'] = 1;
            $db::table('bsa_torder')->where($torderWhere)->update($updateParam);
            $db::commit();

            $returnMsg['code'] = 1000;
            $returnMsg['msg'] = "查单成功!";
//            $returnMsgData = json_encode($has['data']);
//            ltrim($returnMsgData, "[");
//            rtrim($returnMsgData, "]");
//            $returnMsg['data'] = $returnMsgData;
            $returnMsg['data'] = $has['data'];
            return json_encode($returnMsg);
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "系统错误! system error" . $e->getMessage();
            return json_encode($returnMsg);
        }
    }

    /**
     * 余额
     * @return false|string
     */
    public function balance(Request $request)
    {
        try {
            $param = $request->get();
//            $param = json_decode($data, true);
            if (!isset($param['apiMerchantNo']) || empty($param['apiMerchantNo'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.apiMerchantNo must be require";
                return json_encode($returnMsg);
            }

            if (!isset($param['sign']) || empty($param['sign'])) {
                $returnMsg['code'] = 2002;
                $returnMsg['msg'] = "参数错误!.sign must be require";
                return json_encode($returnMsg);
            }

            $db = new Db();
            $where['merchant_sign'] = $param['apiMerchantNo'];
            //验证商户
            $validatetmerchant = $db::table('bsa_tmerchant')->where($where)->find();
            if (empty($validatetmerchant)) {
                $returnMsg['code'] = 2004;
                $returnMsg['msg'] = "无效商户!";
                $returnMsg['balance'] = "0.00";
                return json_encode($returnMsg);
            }
            //验签
            $orderSign = $param['sign'];
            unset($param['sign']);
            ksort($param);
            $returnMsg = array();
            if ($orderSign != strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']))) {
//                var_dump(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token']);
//                var_dump(strtoupper(md5(urldecode(http_build_query($param)) . "&key=" . $validatetmerchant['token'])));
//                exit;
                $returnMsg['code'] = 2006;
                $returnMsg['msg'] = "签名无效!";
                return json_encode($returnMsg);
            }
            //查询推单
            $torderWhere['apiMerchantNo'] = $param['apiMerchantNo'];
            $torderWhere['orderStatus'] = 1;
//            $torderWhere['status'] = 1;
            $tOrderModel = new TorderModel();
            $has = $tOrderModel->getTmerchangBalanceByWhere($torderWhere);
//            var_dump($has);exit;
            if ($has['code'] != 0) {
                $returnMsg['code'] = 2102;
                $returnMsg['msg'] = "订单不存在!";
                $returnMsg['balance'] = "0.00";
                return json_encode($returnMsg);
            }
            $tOrderData = $has['data'];
            $returnMsg['code'] = 2100;
            $returnMsg['msg'] = "查单成功!";
            $returnMsg['balance'] = $has['data'];
            return json_encode($returnMsg);
        } catch (\Exception $e) {
            $returnMsg['code'] = 1009;
            $returnMsg['msg'] = "系统错误错误!" . $e->getMessage();
            $returnMsg['balance'] = '0.00';
            return json_encode($returnMsg);
        }
    }
}