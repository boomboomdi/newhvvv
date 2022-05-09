<?php

namespace app\api\controller;

use think\Controller;
use think\Db;
use app\common\model\OrderhexiaoModel;
use app\common\model\OrderModel;
use app\api\validate\OrderinfoValidate;
use app\api\validate\CheckPhoneAmountNotifyValidate;
use think\Request;
use think\Validate;

class Orderinfo extends Controller
{
    /**
     * 正式入口
     * @param Request $request
     * @return void
     */
    public function order(Request $request)
    {
        session_write_close();
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        try {
            logs(json_encode(['message' => $message, 'line' => $message]), 'order_fist');
            $validate = new OrderinfoValidate();
            if (!$validate->check($message)) {
                return apiJsonReturn(-1, '', $validate->getError());
            }
            $db = new Db();
            //验证商户
            $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
            if (empty($token)) {
                return apiJsonReturn(10001, "商户验证失败！");
            }
            $sig = md5($message['merchant_sign'] . $message['order_no'] . $message['amount'] . $message['time'] . $token);
            if ($sig != $message['sign']) {
                logs(json_encode(['orderParam' => $message, 'doMd5' => $sig]), 'orderParam_signfail');
                return apiJsonReturn(10006, "验签失败！");
            }
            $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                return apiJsonReturn(11001, "单号重复！");
            }

            //$user_id = $message['user_id'];  //用户标识
            // 根据user_id  未付款次数 限制下单 end

            $orderMe = uuidA();

            $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
            if (!empty($orderFind)) {
                $orderMe = uuidA();
            }
            $orderNoFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->find();
            if (!empty($orderNoFind)) {
                return apiJsonReturn(10066, "该订单号已存在！");
            }
            //1、入库
            $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
            $insertOrderData['order_no'] = $message['order_no'];  //商户订单号
            $insertOrderData['order_status'] = 3;  //  1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。
            $insertOrderData['order_me'] = $orderMe; //本平台订单号
            $insertOrderData['amount'] = $message['amount']; //支付金额
            $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
            $insertOrderData['payment'] = "HUAFEI"; //alipay
            $insertOrderData['add_time'] = time();  //入库时间
            $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 notify url


            $orderModel = new OrderModel();
            $createOrderOne = $orderModel->addOrder($insertOrderData);
            if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {

                return apiJsonReturn(10008, $createOrderOne['msg'] . $createOrderOne['code']);
            }
            //2、分配核销单
            $orderHXModel = new OrderhexiaoModel();
            $getUseHxOrderRes = $orderHXModel->getUseHxOrder($insertOrderData);
            if (!isset($getUseHxOrderRes['code']) || $getUseHxOrderRes['code'] != 0) {

                logs(json_encode(['action' => 'getUseHxOrderRes',
                    'insertOrderData' => $insertOrderData,
                    'getUseHxOrderRes' => $getUseHxOrderRes
                ]), 'getUseHxOrder_log');

                //修改订单为下单失败状态。
                $updateOrderStatus['last_use_time'] = time();
                $updateOrderStatus['order_desc'] = "下单失败|" . $getUseHxOrderRes['msg'];
                $orderModel->where('order_no', $insertOrderData['order_no'])->update($updateOrderStatus);
                return apiJsonReturn(10010, $getUseHxOrderRes['msg'], "");
            }
            $updateOrderStatus['order_status'] = 4;   //等待支付状态
            $updateOrderStatus['check_times'] = 1;   //下单成功就查询一次
            $updateOrderStatus['order_pay'] = $getUseHxOrderRes['data']['order_no'];   //匹配核销单订单号
            $updateOrderStatus['order_limit_time'] = time() + 900;  //订单表 限制使用时间
            $updateOrderStatus['start_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
            $updateOrderStatus['last_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
            $updateOrderStatus['end_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'] + $insertOrderData['amount'];  //应到余额
            $updateOrderStatus['next_check_time'] = time() + 90;   //下次查询余额时间
            $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
            $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
            $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
            $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
//            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
            $imgUrl = "http://175.178.195.147:9090/upload/tengxun.jpg";
            $imgUrl = urlencode($imgUrl);
            $limitTime = ($updateOrderStatus['order_limit_time'] - 720);
            $url = $url . "?order_id=" . $message['order_no'] . "&amount=" . $message['amount'] . "&phone=" . $getUseHxOrderRes['data']['account'] . "&img_url=" . $imgUrl . "&limit_time=" . $limitTime;
            $updateOrderStatus['qr_url'] = $url;   //支付订单
            $updateWhere['order_no'] = $message['order_no'];
            $localOrderUpdateRes = $orderModel->localUpdateOrder($updateWhere, $updateOrderStatus);
//            logs(json_encode([
//                'orderWhere' => $updateWhere,
//                'updateOrderStatus' => $updateOrderStatus,
//                'localOrderUpdateRes' => $localOrderUpdateRes
//            ]), 'localhostUpdateOrder');
//            $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
            if (!isset($localOrderUpdateRes['code']) || $localOrderUpdateRes['code'] != 0) {

                return apiJsonReturn(10009, "下单失败！");
            }

            return apiJsonReturn(10000, "下单成功", $url);
        } catch (\Error $error) {

            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
            ]), 'orderError');
            return json(msg(-22, '', $error->getMessage() . $error->getLine()));
        } catch (\Exception $exception) {

            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderException');
            return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
        }
    }


    public function orderNew(Request $request)
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        try {
            logs(json_encode(['message' => $message, 'line' => $message]), 'order_fist');
            $validate = new OrderinfoValidate();
            if (!$validate->check($message)) {
                return apiJsonReturn(-1, '', $validate->getError());
            }
            $db = new Db();
            //验证商户
            $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
            if (empty($token)) {
                return apiJsonReturn(10001, "商户验证失败！");
            }
            $sig = md5($message['merchant_sign'] . $message['order_no'] . $message['amount'] . $message['time'] . $token);
            if ($sig != $message['sign']) {
                logs(json_encode(['orderParam' => $message, 'doMd5' => $sig]), 'orderParam_signfail');
                return apiJsonReturn(10006, "验签失败！");
            }
            $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
            if ($orderFind > 0) {
                return apiJsonReturn(11001, "单号重复！");
            }

            //$user_id = $message['user_id'];  //用户标识
            // 根据user_id  未付款次数 限制下单 end

            $orderMe = uuidA();

            $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
            if (!empty($orderFind)) {
                $orderMe = uuidA();
            }
            $orderNoFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->find();
            if (!empty($orderNoFind)) {
                return apiJsonReturn(10066, "该订单号已存在！");
            }
            //1、入库
            $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
            $insertOrderData['order_no'] = $message['order_no'];  //商户订单号
            $insertOrderData['order_status'] = 3;  //  1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。
            $insertOrderData['order_me'] = $orderMe; //本平台订单号
            $insertOrderData['amount'] = $message['amount']; //支付金额
            $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
            $insertOrderData['payment'] = "HUAFEI"; //alipay
            $insertOrderData['add_time'] = time();  //入库时间
            $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 notify url


            $orderModel = new OrderModel();
            $createOrderOne = $orderModel->addOrder($insertOrderData);
            if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {

                return apiJsonReturn(10008, $createOrderOne['msg'] . $createOrderOne['code']);
            }
            //2、分配核销单
            $orderHXModel = new OrderhexiaoModel();
            $getUseHxOrderRes = $orderHXModel->getUseHxOrder($insertOrderData);
            if (!isset($getUseHxOrderRes['code']) || $getUseHxOrderRes['code'] != 0) {

                logs(json_encode(['action' => 'getUseHxOrderRes',
                    'insertOrderData' => $insertOrderData,
                    'getUseHxOrderRes' => $getUseHxOrderRes
                ]), 'getUseHxOrder_log');

                //修改订单为下单失败状态。
                $updateOrderStatus['last_use_time'] = time();
                $updateOrderStatus['order_desc'] = "下单失败|" . $getUseHxOrderRes['msg'];
                $orderModel->where('order_no', $insertOrderData['order_no'])->update($updateOrderStatus);
                return apiJsonReturn(10010, $getUseHxOrderRes['msg'], "");
            }
            $updateOrderStatus['order_status'] = 4;   //等待支付状态
            $updateOrderStatus['check_times'] = 1;   //下单成功就查询一次
            $updateOrderStatus['order_pay'] = $getUseHxOrderRes['data']['order_no'];   //匹配核销单订单号
            $updateOrderStatus['order_limit_time'] = time() + 900;  //订单表 限制使用时间
            $updateOrderStatus['start_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
            $updateOrderStatus['last_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
            $updateOrderStatus['end_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'] + $insertOrderData['amount'];  //应到余额
            $updateOrderStatus['next_check_time'] = time() + 90;   //下次查询余额时间
            $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
            $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
            $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
            $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
//            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
            $imgUrl = "http://175.178.195.147:9090/upload/tengxun.jpg";
            $imgUrl = urlencode($imgUrl);
            $limitTime = ($updateOrderStatus['order_limit_time'] - 720);
            $url = $url . "?order_id=" . $message['order_no'] . "&amount=" . $message['amount'] . "&phone=" . $getUseHxOrderRes['data']['account'] . "&img_url=" . $imgUrl . "&limit_time=" . $limitTime;
            $updateOrderStatus['qr_url'] = $url;   //支付订单
            $updateWhere['order_no'] = $message['order_no'];
            $localOrderUpdateRes = $orderModel->localUpdateOrder($updateWhere, $updateOrderStatus);
//            logs(json_encode([
//                'orderWhere' => $updateWhere,
//                'updateOrderStatus' => $updateOrderStatus,
//                'localOrderUpdateRes' => $localOrderUpdateRes
//            ]), 'localhostUpdateOrder');
//            $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
            if (!isset($localOrderUpdateRes['code']) || $localOrderUpdateRes['code'] != 0) {

                return apiJsonReturn(10009, "下单失败！");
            }

            return apiJsonReturn(10000, "下单成功", $url);
        } catch (\Error $error) {

            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
            ]), 'orderError');
            return json(msg(-22, '', $error->getMessage() . $error->getLine()));
        } catch (\Exception $exception) {

            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderException');
            return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
        }
    }

    public function order1()
    {
        $url = "http://175.178.195.147:9090/api/orderinfo/orderNew";
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        $param = $message;
        return $this->sock_post($url, $param);
    }

    function sock_post($url, $query)
    {
        $info = parse_url($url);
        $fp = fsockopen($info["host"], 9090, $errno, $errstr, 30);
        $head = "POST " . $info['path'] . "?" . $info["query"] . " HTTP/1.0\r\n";
        $head .= "Host: " . $info['host'] . "\r\n";
        $head .= "Referer: http://" . $info['host'] . $info['path'] . "\r\n";
        $head .= "Content-type: application/x-www-form-urlencoded\r\n";
        $head .= "Content-Length: " . strlen(trim($query)) . "\r\n";
        $head .= "\r\n";
        $head .= trim($query);
        $write = fputs($fp, $head);
        while (!feof($fp)) {
            $line = fread($fp, 4096);
            echo $line;
        }
    }

    function curlLocal($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $port = $port ? $port : 80;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) $path .= '?' . $query;
        if ($scheme == 'https') {
            $host = 'ssl://' . $host;
        }

        $fp = fsockopen($host, $port, $error_code, $error_msg, 1);
        if (!$fp) {
            return array('error_code' => $error_code, 'error_msg' => $error_msg);
        } else {
            stream_set_blocking($fp, true);//开启了手册上说的非阻塞模式
            stream_set_timeout($fp, 1);//设置超时
            $header = "GET $path HTTP/1.1\r\n";
            $header .= "Host: $host\r\n";
            $header .= "Connection: close\r\n\r\n";//长连接关闭
            fwrite($fp, $header);
            usleep(1000); // 这一句也是关键，如果没有这延时，可能在nginx服务器上就无法执行成功
            fclose($fp);
            return array('error_code' => 0);
        }
    }

    public function order11()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        try {

            $pid = pcntl_fork();    //创建⼦进程
            if ($pid == -1) {
                //错误处理：创建⼦进程失败时返回-1.
                return apiJsonReturn(-1, 'could not order');
//                die('could not fork');
            } else if ($pid) {
                //如果不需要阻塞进程，⽽⼜想得到⼦进程的退出状态，则可以注释掉pcntl_wait($status)语句，或写成：
                pcntl_wait($status, WNOHANG); //等待⼦进程中断，防⽌⼦进程成为僵⼫进程。
                //⽗进程会得到⼦进程号，所以这⾥是⽗进程执⾏的逻辑
                try {
                    $validate = new OrderinfoValidate();
                    if (!$validate->check($message)) {
                        return apiJsonReturn(-1, '', $validate->getError());
                    }
                    $db = new Db();
                    //验证商户
                    $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
                    if (empty($token)) {
                        return apiJsonReturn(10001, "商户验证失败！");
                    }
                    $sig = md5($message['merchant_sign'] . $message['order_no'] . $message['amount'] . $message['time'] . $token);
                    if ($sig != $message['sign']) {
                        logs(json_encode(['orderParam' => $message, 'doMd5' => $sig]), 'orderParam_signfail');
                        return apiJsonReturn(10006, "验签失败！");
                    }
                    $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
                    if ($orderFind > 0) {
                        return apiJsonReturn(11001, "单号重复！");
                    }

                    //$user_id = $message['user_id'];  //用户标识
                    // 根据user_id  未付款次数 限制下单 end

                    $orderMe = uuidA();

                    $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
                    if (!empty($orderFind)) {
                        $orderMe = uuidA();
                    }
                    $orderNoFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->find();
                    if (!empty($orderNoFind)) {
                        return apiJsonReturn(10066, "该订单号已存在！");
                    }
                    //1、入库
                    $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
                    $insertOrderData['order_no'] = $message['order_no'];  //商户订单号
                    $insertOrderData['order_status'] = 3;  //  1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。
                    $insertOrderData['order_me'] = $orderMe; //本平台订单号
                    $insertOrderData['amount'] = $message['amount']; //支付金额
                    $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
                    $insertOrderData['payment'] = "HUAFEI"; //alipay
                    $insertOrderData['add_time'] = time();  //入库时间
                    $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 notify url

                    $orderModel = new OrderModel();
                    $createOrderOne = $orderModel->addOrder($insertOrderData);
                    if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {

                        return apiJsonReturn(10008, $createOrderOne['msg'] . $createOrderOne['code']);
                    }
                    //2、分配核销单
                    $orderHXModel = new OrderhexiaoModel();
                    $getUseHxOrderRes = $orderHXModel->getUseHxOrder($insertOrderData);
                    if (!isset($getUseHxOrderRes['code']) || $getUseHxOrderRes['code'] != 0) {

                        logs(json_encode(['action' => 'getUseHxOrderRes',
                            'insertOrderData' => $insertOrderData,
                            'getUseHxOrderRes' => $getUseHxOrderRes
                        ]), 'getUseHxOrder_log');

                        //修改订单为下单失败状态。
                        $updateOrderStatus['last_use_time'] = time();
                        $updateOrderStatus['order_desc'] = "下单失败|" . $getUseHxOrderRes['msg'];
                        $orderModel->where('order_no', $insertOrderData['order_no'])->update($updateOrderStatus);
                        return apiJsonReturn(10010, $getUseHxOrderRes['msg'], "");
                    }
                    $updateOrderStatus['order_status'] = 4;   //等待支付状态
                    $updateOrderStatus['check_times'] = 1;   //下单成功就查询一次
                    $updateOrderStatus['order_pay'] = $getUseHxOrderRes['data']['order_no'];   //匹配核销单订单号
                    $updateOrderStatus['order_limit_time'] = time() + 900;  //订单表 限制使用时间
                    $updateOrderStatus['start_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
                    $updateOrderStatus['last_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
                    $updateOrderStatus['end_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'] + $insertOrderData['amount'];  //应到余额
                    $updateOrderStatus['next_check_time'] = time() + 90;   //下次查询余额时间
                    $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
                    $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
                    $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
                    $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
//            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
                    $imgUrl = "http://175.178.195.147:9090/upload/tengxun.jpg";
                    $imgUrl = urlencode($imgUrl);
                    $limitTime = ($updateOrderStatus['order_limit_time'] - 720);
                    $url = $url . "?order_id=" . $message['order_no'] . "&amount=" . $message['amount'] . "&phone=" . $getUseHxOrderRes['data']['account'] . "&img_url=" . $imgUrl . "&limit_time=" . $limitTime;
                    $updateOrderStatus['qr_url'] = $url;   //支付订单
                    $updateWhere['order_no'] = $message['order_no'];
                    $localOrderUpdateRes = $orderModel->localUpdateOrder($updateWhere, $updateOrderStatus);
//            logs(json_encode([
//                'orderWhere' => $updateWhere,
//                'updateOrderStatus' => $updateOrderStatus,
//                'localOrderUpdateRes' => $localOrderUpdateRes
//            ]), 'localhostUpdateOrder');
//            $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                    if (!isset($localOrderUpdateRes['code']) || $localOrderUpdateRes['code'] != 0) {

                        return apiJsonReturn(10009, "下单失败！");
                    }

                    return apiJsonReturn(10000, "下单成功", $url);
                } catch (\Error $error) {

                    logs(json_encode(['file' => $error->getFile(),
                        'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
                    ]), 'orderError');
                    return json(msg(-22, '', $error->getMessage() . $error->getLine()));
                } catch (\Exception $exception) {
                    logs(json_encode(['file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'errorMessage' => $exception->getMessage()
                    ]), 'orderException');
                    return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
                }
            } else {
                try {
                    $validate = new OrderinfoValidate();
                    if (!$validate->check($message)) {
                        return apiJsonReturn(-1, '', $validate->getError());
                    }
                    $db = new Db();
                    //验证商户
                    $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
                    if (empty($token)) {
                        return apiJsonReturn(10001, "商户验证失败！");
                    }
                    $sig = md5($message['merchant_sign'] . $message['order_no'] . $message['amount'] . $message['time'] . $token);
                    if ($sig != $message['sign']) {
                        logs(json_encode(['orderParam' => $message, 'doMd5' => $sig]), 'orderParam_signfail');
                        return apiJsonReturn(10006, "验签失败！");
                    }
                    $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
                    if ($orderFind > 0) {
                        return apiJsonReturn(11001, "单号重复！");
                    }

                    //$user_id = $message['user_id'];  //用户标识
                    // 根据user_id  未付款次数 限制下单 end

                    $orderMe = uuidA();

                    $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
                    if (!empty($orderFind)) {
                        $orderMe = uuidA();
                    }
                    $orderNoFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->find();
                    if (!empty($orderNoFind)) {
                        return apiJsonReturn(10066, "该订单号已存在！");
                    }
                    //1、入库
                    $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
                    $insertOrderData['order_no'] = $message['order_no'];  //商户订单号
                    $insertOrderData['order_status'] = 3;  //  1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。
                    $insertOrderData['order_me'] = $orderMe; //本平台订单号
                    $insertOrderData['amount'] = $message['amount']; //支付金额
                    $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
                    $insertOrderData['payment'] = "HUAFEI"; //alipay
                    $insertOrderData['add_time'] = time();  //入库时间
                    $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 notify url

                    $orderModel = new OrderModel();
                    $createOrderOne = $orderModel->addOrder($insertOrderData);
                    if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {

                        return apiJsonReturn(10008, $createOrderOne['msg'] . $createOrderOne['code']);
                    }
                    //2、分配核销单
                    $orderHXModel = new OrderhexiaoModel();
                    $getUseHxOrderRes = $orderHXModel->getUseHxOrder($insertOrderData);
                    if (!isset($getUseHxOrderRes['code']) || $getUseHxOrderRes['code'] != 0) {

                        logs(json_encode(['action' => 'getUseHxOrderRes',
                            'insertOrderData' => $insertOrderData,
                            'getUseHxOrderRes' => $getUseHxOrderRes
                        ]), 'getUseHxOrder_log');

                        //修改订单为下单失败状态。
                        $updateOrderStatus['last_use_time'] = time();
                        $updateOrderStatus['order_desc'] = "下单失败|" . $getUseHxOrderRes['msg'];
                        $orderModel->where('order_no', $insertOrderData['order_no'])->update($updateOrderStatus);
                        return apiJsonReturn(10010, $getUseHxOrderRes['msg'], "");
                    }
                    $updateOrderStatus['order_status'] = 4;   //等待支付状态
                    $updateOrderStatus['check_times'] = 1;   //下单成功就查询一次
                    $updateOrderStatus['order_pay'] = $getUseHxOrderRes['data']['order_no'];   //匹配核销单订单号
                    $updateOrderStatus['order_limit_time'] = time() + 900;  //订单表 限制使用时间
                    $updateOrderStatus['start_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
                    $updateOrderStatus['last_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
                    $updateOrderStatus['end_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'] + $insertOrderData['amount'];  //应到余额
                    $updateOrderStatus['next_check_time'] = time() + 90;   //下次查询余额时间
                    $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
                    $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
                    $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
                    $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
//            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
                    $imgUrl = "http://175.178.195.147:9090/upload/tengxun.jpg";
                    $imgUrl = urlencode($imgUrl);
                    $limitTime = ($updateOrderStatus['order_limit_time'] - 720);
                    $url = $url . "?order_id=" . $message['order_no'] . "&amount=" . $message['amount'] . "&phone=" . $getUseHxOrderRes['data']['account'] . "&img_url=" . $imgUrl . "&limit_time=" . $limitTime;
                    $updateOrderStatus['qr_url'] = $url;   //支付订单
                    $updateWhere['order_no'] = $message['order_no'];
                    $localOrderUpdateRes = $orderModel->localUpdateOrder($updateWhere, $updateOrderStatus);
//            logs(json_encode([
//                'orderWhere' => $updateWhere,
//                'updateOrderStatus' => $updateOrderStatus,
//                'localOrderUpdateRes' => $localOrderUpdateRes
//            ]), 'localhostUpdateOrder');
//            $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                    if (!isset($localOrderUpdateRes['code']) || $localOrderUpdateRes['code'] != 0) {

                        return apiJsonReturn(10009, "下单失败！");
                    }

                    return apiJsonReturn(10000, "下单成功", $url);
                } catch (\Error $error) {

                    logs(json_encode(['file' => $error->getFile(),
                        'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
                    ]), 'orderError');
                    return json(msg(-22, '', $error->getMessage() . $error->getLine()));
                } catch (\Exception $exception) {

                    logs(json_encode(['file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'errorMessage' => $exception->getMessage()
                    ]), 'orderException');
                    return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
                }
            }
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(),
                'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
            ]), 'orderForkError');
            return json(msg(-22, '', $error->getMessage() . $error->getLine()));
        } catch (\Exception $exception) {

            logs(json_encode(['file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()
            ]), 'orderForkException');
            return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
        }
    }

    public function order3()
    {
        while (1)//循环采用3个进程
        {
            //declare(ticks=1);
            $bWaitFlag = FALSE; // 是否等待进程结束
            //$bWaitFlag = TRUE; // 是否等待进程结束
            $intNum = 3; // 进程总数
            $pids = array(); // 进程PID数组
            for ($i = 0; $i < $intNum; $i++) {
                $pids[$i] = pcntl_fork();// 产生子进程，而且从当前行之下开试运行代码，而且不继承父进程的数据信息
                /*if($pids[$i])//父进程
                {
                //echo $pids[$i]."parent"."$i -> " . time(). "\n";
                }
                */
                if ($pids[$i] == -1) {
                    echo "couldn't fork" . "\n";
                } elseif (!$pids[$i]) {
                    sleep(1);
                    echo "\n" . "第" . $i . "个进程 -> " . time() . "\n";
                    //$url=" 抓取页面的例子
                    //$content = file_get_contents($url);
                    //file_put_contents('message.txt',$content);
                    //echo "\n"."第".$i."个进程 -> " ."抓取页面".$i."-> " . time()."\n";
                    exit(0);//子进程要exit否则会进行递归多进程，父进程不要exit否则终止多进程
                }
                if ($bWaitFlag) {
                    pcntl_waitpid($pids[$i], $status, WUNTRACED);
                    echo "wait $i -> " . time() . "\n";
                }
            }
            sleep(1);
        }
    }

    public function order2()
    {
        $fp = fopen("/www/wwwroot/hvvv/application/api/controller/order.lock", "r");
        if (flock($fp, LOCK_EX)) {
            // 处理商品数据
            $data = @file_get_contents('php://input');
            $message = json_decode($data, true);
            try {
                logs(json_encode(['message' => $message, 'line' => $message]), 'order_fist');
                $validate = new OrderinfoValidate();
                if (!$validate->check($message)) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return apiJsonReturn(-1, '', $validate->getError());
                }
                $db = new Db();
                //验证商户
                $token = $db::table('bsa_merchant')->where('merchant_sign', '=', $message['merchant_sign'])->find()['token'];
                if (empty($token)) {

                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return apiJsonReturn(10001, "商户验证失败！");
                }
                $sig = md5($message['merchant_sign'] . $message['order_no'] . $message['amount'] . $message['time'] . $token);
                if ($sig != $message['sign']) {

                    flock($fp, LOCK_UN);
                    fclose($fp);
                    logs(json_encode(['orderParam' => $message, 'doMd5' => $sig]), 'orderParam_signfail');
                    return apiJsonReturn(10006, "验签失败！");
                }
                $orderFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->count();
                if ($orderFind > 0) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return apiJsonReturn(11001, "单号重复！");
                }

                //$user_id = $message['user_id'];  //用户标识
                // 根据user_id  未付款次数 限制下单 end

                $orderMe = uuidA();

                $orderFind = $db::table('bsa_order')->where('order_me', '=', $orderMe)->find();
                if (!empty($orderFind)) {
                    $orderMe = uuidA();
                }
                $orderNoFind = $db::table('bsa_order')->where('order_no', '=', $message['order_no'])->find();
                if (!empty($orderNoFind)) {

                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return apiJsonReturn(10066, "该订单号已存在！");
                }
                //1、入库
                $insertOrderData['merchant_sign'] = $message['merchant_sign'];  //商户
                $insertOrderData['order_no'] = $message['order_no'];  //商户订单号
                $insertOrderData['order_status'] = 3;  //  1、支付成功（下单成功）！2、支付失败（下单成功）！3、下单失败！4、等待支付（下单成功）！5、已手动回调。
                $insertOrderData['order_me'] = $orderMe; //本平台订单号
                $insertOrderData['amount'] = $message['amount']; //支付金额
                $insertOrderData['payable_amount'] = $message['amount'];  //应付金额
                $insertOrderData['payment'] = "HUAFEI"; //alipay
                $insertOrderData['add_time'] = time();  //入库时间
                $insertOrderData['notify_url'] = $message['notify_url']; //下单回调地址 notify url

//                throw Exception('lock lockfile fail');


                $orderModel = new OrderModel();
                $createOrderOne = $orderModel->addOrder($insertOrderData);
                if (!isset($createOrderOne['code']) || $createOrderOne['code'] != '0') {

                    flock($fp, LOCK_UN);
                    fclose($fp);

                    return apiJsonReturn(10008, $createOrderOne['msg'] . $createOrderOne['code']);
                }
                //2、分配核销单
                $orderHXModel = new OrderhexiaoModel();
                $getUseHxOrderRes = $orderHXModel->getUseHxOrder($insertOrderData);
                if (!isset($getUseHxOrderRes['code']) || $getUseHxOrderRes['code'] != 0) {

                    logs(json_encode(['action' => 'getUseHxOrderRes',
                        'insertOrderData' => $insertOrderData,
                        'getUseHxOrderRes' => $getUseHxOrderRes
                    ]), 'getUseHxOrder_log');

                    //修改订单为下单失败状态。
                    $updateOrderStatus['last_use_time'] = time();
                    $updateOrderStatus['order_desc'] = "下单失败|" . $getUseHxOrderRes['msg'];
                    $orderModel->where('order_no', $insertOrderData['order_no'])->update($updateOrderStatus);

                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return apiJsonReturn(10010, $getUseHxOrderRes['msg'], "");
                }
                $updateOrderStatus['order_status'] = 4;   //等待支付状态
                $updateOrderStatus['check_times'] = 1;   //下单成功就查询一次
                $updateOrderStatus['order_pay'] = $getUseHxOrderRes['data']['order_no'];   //匹配核销单订单号
                $updateOrderStatus['order_limit_time'] = time() + 900;  //订单表 限制使用时间
                $updateOrderStatus['start_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
                $updateOrderStatus['last_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'];  //开单余额
                $updateOrderStatus['end_check_amount'] = $getUseHxOrderRes['data']['last_check_amount'] + $insertOrderData['amount'];  //应到余额
                $updateOrderStatus['next_check_time'] = time() + 90;   //下次查询余额时间
                $updateOrderStatus['account'] = $getUseHxOrderRes['data']['account'];   //匹配核销单账号
                $updateOrderStatus['write_off_sign'] = $getUseHxOrderRes['data']['write_off_sign'];   //匹配核销单核销商标识
                $updateOrderStatus['order_desc'] = "下单成功,等待支付！";
                $url = "http://175.178.241.238/pay/#/huafei";
//            订单号order_id   金额 amount   手机号 phone  二维码链接 img_url    有效时间 limit_time 秒
//            $imgUrl = "http://175.178.195.147:9090/upload/huafei.jpg";
                $imgUrl = "http://175.178.195.147:9090/upload/tengxun.jpg";
                $imgUrl = urlencode($imgUrl);
                $limitTime = ($updateOrderStatus['order_limit_time'] - 720);
                $url = $url . "?order_id=" . $message['order_no'] . "&amount=" . $message['amount'] . "&phone=" . $getUseHxOrderRes['data']['account'] . "&img_url=" . $imgUrl . "&limit_time=" . $limitTime;
                $updateOrderStatus['qr_url'] = $url;   //支付订单
                $updateWhere['order_no'] = $message['order_no'];
                $localOrderUpdateRes = $orderModel->localUpdateOrder($updateWhere, $updateOrderStatus);
//            logs(json_encode([
//                'orderWhere' => $updateWhere,
//                'updateOrderStatus' => $updateOrderStatus,
//                'localOrderUpdateRes' => $localOrderUpdateRes
//            ]), 'localhostUpdateOrder');
//            $orderModel->where('order_no', '=', $insertOrderData['order_no'])->update($updateOrderStatus);
                if (!isset($localOrderUpdateRes['code']) || $localOrderUpdateRes['code'] != 0) {

                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return apiJsonReturn(10009, "下单失败！");
                }

                flock($fp, LOCK_UN);
                fclose($fp);
                return apiJsonReturn(10000, "下单成功", $url);
            } catch (\Error $error) {

                flock($fp, LOCK_UN);
                fclose($fp);
                logs(json_encode(['file' => $error->getFile(),
                    'line' => $error->getLine(), 'errorMessage' => $error->getMessage()
                ]), 'orderError');
                return json(msg(-22, '', $error->getMessage() . $error->getLine()));
            } catch (\Exception $exception) {
                flock($fp, LOCK_UN);
                fclose($fp);
                logs(json_encode(['file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'errorMessage' => $exception->getMessage()
                ]), 'orderException');
                return json(msg(-11, '', $exception->getMessage() . $exception->getFile() . $exception->getLine()));
            }
        } else {
            return json(msg(-33, '', "lock fail"));
        }
    }

    /**
     * 引导页面查询订单状态
     */
    public function getOrderInfo(Request $request)
    {
        header('Access-Control-Allow-Origin:*');
        header("Access-Control-Allow-Credentials:true");
        header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept,Authorization");
        header('Access-Control-Allow-Methods:GET,POST,PUT,DELETE,OPTIONS,PATCH');
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        if (!isset($message['order_no']) || empty($message['order_no'])) {
            return json(msg(-1, '', '单号有误！'));
        }
        try {
            $orderModel = new OrderModel();
            $where['order_no'] = $message['order_no'];
            $orderInfo = $orderModel->where($where)->find();

            if (empty($orderInfo['order_no'])) {
                return json(msg(-2, '', '无此推单！'));
            }
            if ($orderInfo['order_status'] != 4) {

                return json(msg(-3, '', '请重新下单！'));
            }

            if (($orderInfo['order_limit_time'] - 720) < time()) {
                return json(msg(-4, '', '订单超时，请重新下单'));
            }

            return json(msg(0, ($orderInfo['order_limit_time'] - 720), "success"));

        } catch (\Exception $exception) {
            logs(json_encode(['param' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()]), 'orderInfoException');
            return apiJsonReturn(-11, "orderInfo exception!" . $exception->getMessage());
        } catch (\Error $error) {
            logs(json_encode(['param' => $message,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'orderInfoError');
            return json(msg(-22, '', 'orderInfo error!' . $error->getMessage()));
        }
    }

    //结果回调
    public function checkPhoneAmountNotify0076()
    {
        $data = @file_get_contents('php://input');
        $message = json_decode($data, true);
        try {
            logs(json_encode([
                'param' => $message,
                'startTime' => date("Y-m-d H:i:s", time())
            ]), 'checkPhoneAmountNotify0076');
            $validate = new CheckPhoneAmountNotifyValidate();
            if (!$validate->check($message)) {
                return apiJsonReturn(-1, '', $validate->getError());
            }
            $orderModel = new OrderModel();
            $orderWhere['order_no'] = $message['order_no'];  //四方单号
            $orderWhere['account'] = $message['phone'];   //订单匹配手机号
            $orderInfo = $orderModel->where($orderWhere)->find();

            logs(json_encode([
                "time" => date("Y-m-d H:i:s", time()),
                'param' => $message
            ]), 'MatchOrderFailCheckPhoneAmountNotify0076');
            if (empty($orderInfo)) {
                return json(msg(-2, '', '无此订单！'));
            }
            if ($orderInfo['order_status'] == 1) {
                return json(msg(-3, '', '订单已支付！'));
            }
            $db = new Db();
            $checkResult = "第" . ($orderInfo['check_times'] + 1) . "次查询结果" . $message['amount'] . "(" . date("Y-m-d H:i:s") . ")";
            $nextCheckTime = time() + 40;
            if ($orderInfo['check_times'] > 3) {
                $nextCheckTime = time() + 50;
            }
            if ($message['check_status'] != 1) {
                $updateCheckTimesRes = $db::table("bsa_order")->where($orderWhere)
                    ->update([
                        "check_status" => 0,  //查询结束
                        "check_times" => $orderInfo['check_times'] + 1,
                        "next_check_time" => $nextCheckTime,
                        "order_desc" => $checkResult,
                        "check_result" => $checkResult,
                    ]);
                logs(json_encode(['phone' => $orderInfo['account'],
                    "order_no" => $orderInfo['order_no'],
                    "notifyTime" => date("Y-m-d H:i:s", time()),
                    "updateCheckTimesRes" => $updateCheckTimesRes
                ]), '0076updateCheckPhoneAmountFail');
                return json(msg(1, '', '接收成功,更新成功1'));
            }
            //查询成功
            $orderWhere['order_no'] = $orderInfo['order_no'];
            $orderUpdate['check_times'] = $orderInfo['check_times'] + 1;
            $orderUpdate['check_status'] = 0;   //可在查询状态
            $orderUpdate['last_check_amount'] = $message['amount'];
            $orderUpdate['next_check_time'] = $nextCheckTime;
            $orderUpdate['check_result'] = $checkResult;
            $updateCheck = $db::table("bsa_order")->where($orderWhere)
                ->update($orderUpdate);
            if (!$updateCheck) {
                logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                    'action' => "checkNotifySuccess",
                    'message' => json_encode($message),
                    "updateCheck" => $updateCheck
                ]), '0076updateCheckPhoneAmountFail');
            }
            //1、支付到账
            if ($message['amount'] > ($orderInfo['end_check_amount'] - 5)) {
                //本地更新
                $orderHXModel = new OrderhexiaoModel();
                $updateOrderWhere['order_no'] = $orderInfo['order_no'];
                $updateOrderWhere['account'] = $orderInfo['account'];
                $orderHXData = $orderHXModel->where($orderWhere)->find();
                $localUpdateRes = $orderHXModel->orderLocalUpdate($orderInfo);
                logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                    'updateOrderWhere' => $updateOrderWhere,
                    'account' => $orderHXData['account'],
                    'localUpdateRes' => $localUpdateRes
                ]), '0076updateCheckPhoneAmountLocalUpdate');
                if (!isset($localUpdate['code']) || $localUpdate['code'] != 0) {
                    return json(msg(1, '', '接收成功,更新失败！'));
                }
                return json(msg(1, '', '接收成功,更新成功！'));
            }
            return json(msg(1, '', '接收成功,匹配失败！'));
        } catch (\Exception $exception) {
            logs(json_encode(['param' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'errorMessage' => $exception->getMessage()]), 'checkPhoneAmountNotify0076Exception');
            return json(msg(-11, '', '接收异常！'));
        } catch (\Error $error) {
            logs(json_encode(['param' => $message,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'errorMessage' => $error->getMessage()]), 'checkPhoneAmountNotify0076Error');
            return json(msg(-22, '', "接收错误！"));
        }
    }

}