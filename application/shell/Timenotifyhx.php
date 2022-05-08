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

class Timenotifyhx extends Command
{
    protected function configure()
    {
        $this->setName('Timenotifyhxiao')->setDescription('回调核销！');
    }

    /**
     * 定时回调核销
     * @param Output $output
     * @return int|null|void
     * @todo
     */
    protected function execute(Input $input, Output $output)
    {
        try {
            $orderHXModel = new OrderhexiaoModel();
            $orderHXData = $orderHXModel
                ->where('pay_status', '=', 1)
                ->where('notify_status', '=', 0)
                ->where('do_notify', '=', 0)
                ->where('notify_times', '<', 3)
                ->select();
            logs(json_encode(['$orderHXData' => $orderHXData,
                "time" => date("Y-m-d H:i:s", time())]), 'Timenotifyhxiao');
            $db = new Db();
            $totalNum = count($orderHXData);
            if ($totalNum > 0) {
                foreach ($orderHXData as $k => $v) {
                    $orderWhere['order_no'] = $v['order_no'];
                    $orderWhere['account'] = $v['account'];
                    $notifying['do_notify'] = 1;
                    $db::table('bsa_order_hexiao')->where($orderWhere)->update($notifying);
                    $notifyRes = $orderHXModel->orderNotifyToWriteOff($v);
                    if (!isset($notifyRes['code']) || $notifyRes['code'] != 0) {
                        logs(json_encode(['orderData' => $v,
                            "time" => date("Y-m-d H:i:s", time()),
                            "notifyRes" => $notifyRes,
                        ]), 'orderNotifyToWriteOffFail');
                        $db = new Db();
                    }
                    $notifying['do_notify'] = 0;
                    $db::table('bsa_order_hexiao')->where($orderWhere)->update($notifying);
                }
            }
            $output->writeln("Timenotifyhxiao:订单总数" . $totalNum);
        } catch (\Exception $exception) {
            logs(json_encode(['file' => $exception->getFile(), 'line' => $exception->getLine(), 'errorMessage' => $exception->getMessage()]), 'Timenotifyhxiao_exception');
            $output->writeln("Timenotifyhxiao:exception");
        } catch (\Error $error) {
            logs(json_encode(['file' => $error->getFile(), 'line' => $error->getLine(), 'errorMessage' => $error->getMessage()]), 'TiTimenotifyhxiao_error');
            $output->writeln("Timenotifyhxiao:error");
        }

    }
}