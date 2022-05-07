<?php

namespace app\shell;

use app\common\model\OrderhexiaoModel;
use app\common\model\OrderModel;
use think\console\Command;
use think\console\Input;
use think\console\Output;

use app\common\model\SystemConfigModel;
use app\common\model\NotifylogModel;
use think\Db;

class Timecheckorder extends Command
{
    protected function configure()
    {
        $this->setName('Timecheckorder')->setDescription('定时查询订单充值手机余额!');
    }

    /**
     * 定时查询话单余额
     * @param Output $output
     * @return int|null|void
     * @todo
     */
    protected function execute(Input $input, Output $output)
    {
        try {
            $orderHXModel = new OrderhexiaoModel();
            $orderModel = new OrderModel();
            //一直查询  --等待 回调 code!=0  改为status  =2
            $orderData = $orderModel
                ->where('order_status', '=', 4)
                ->where('next_check_time', '<', time())
                ->where('check_status', '=', 0)
                ->where('check_times', '<', 5)
                ->select();
            logs(json_encode(['orderData' => $orderData,
                "sql" => Db::table("bsa_order")->getLastSql(),
                "time" => date("Y-m-d H:i:s", time())]), 'Timecheckorder');
            $db = new Db();
            $totalNum = count($orderData);
            if ($totalNum > 0) {
                foreach ($orderData as $k => $v) {
                    $getResParam['order_no'] = $v['order_no'];
                    $getResParam['phone'] = $v['account'];
                    $checkStartTime = date("Y-m-d H:i:s", time());
                    $getPhoneAmountRes = $orderHXModel->checkPhoneAmount($getResParam);

                    logs(json_encode(['phone' => $v['account'],
                        "order_no" => $v['order_no'],
                        "startTime" => $checkStartTime,
                        "endTime" => date("Y-m-d H:i:s", time()),
                        "getPhoneAmountRes" => $getPhoneAmountRes['data']
                    ]), 'TimecheckdouyincheckPhoneAmount_log');
                    $checkResult = "第" . ($v['check_times'] + 1) . "次查询结果" . $getPhoneAmountRes['data'] . "(" . date("Y-m-d H:i:s") . ")";
                    $nextCheckTime = time() + 40;
                    if ($v['check_times'] > 3) {
                        $nextCheckTime = time() + 50;
                    }
                    if (!isset($getPhoneAmountRes['code']) && $getPhoneAmountRes['code'] != 0) {
                        $orderWhere['order_no'] = $v['order_no'];
                        $db::table("bsa_order")->where($orderWhere)
                            ->update([
                                "check_times" => $v['check_times'] + 1,
                                "next_check_time" => $nextCheckTime,
                                "check_result" => $checkResult,
                            ]);
                    } else {
                        //查询成功
                        //1、支付到账
                        if ($getPhoneAmountRes['data'] == $v['end_check_amount']) {
                            //1、回调核销商
                            $localUpdate = $orderHXModel->orderLocalUpDate($v, 1);
                            if (!isset($localUpdate['code']) || $localUpdate['code'] == 0) {
                                logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                                    'order_no' => $v['order_no'],
                                    'phone' => $v['account'],
                                    "localUpdateFail" => json_encode($localUpdate)
                                ]), 'Timecheckorder_log');
                            }
                            //2、回调四方
                        } else if ($getPhoneAmountRes['data'] > $v['start_check_amount'] && $getPhoneAmountRes['data'] < $v['end_check_amount']) {
                            //2、余额大于下单匹配余额小于应该成功余额

                        } else {
                            //3、余额小于等于初始查询余额

                        }
                        $orderWhere['order_no'] = $v['order_no'];
                        $orderUpdate['check_times'] = $v['check_times'] + 1;
                        $orderUpdate['last_check_amount'] = $getPhoneAmountRes['data'];
                        $orderUpdate['check_times'] = $checkResult;
                        $updateCheck = $db::table("bsa_order")->where($orderWhere)
                            ->update($orderUpdate);
                        if (!$updateCheck) {
                            logs(json_encode(["time" => date("Y-m-d H:i:s", time()),
                                'action' => $v['order_no'],
                                'order_no' => $v['order_no'],
                                'phone' => $v['account'],
                                "getPhoneAmountRes" => $getPhoneAmountRes['data']
                            ]), 'Timecheckorder_log');
                        }
                    }
                }

            }
            $output->writeln("Timecheckorder:订单总数" . $totalNum);
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'Timecheckorder_exception');
            $output->writeln("Timecheckorder:exception");
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'Timecheckorder_error');
            $output->writeln("Timecheckorder:error");
        }

    }
}