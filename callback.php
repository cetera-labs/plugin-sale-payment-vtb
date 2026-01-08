<?php
$application->connectDb();
$application->initSession();
$application->initPlugins();

ob_start();

try {
    $source = file_get_contents("php://input");
    $requestBody = json_decode($source, true);

    $headers = getallheaders();

    print_r($requestBody);
    print_r($headers);
    $order = \Sale\Order::getById($requestBody["object"]["orderId"]);
    $gateway = $order->getPaymentGateway();

    if ($requestBody["type"] == "PAYMENT") {
        $gateway->saveTransaction(
            $requestBody["object"]["paymentId"],
            $requestBody
        );
        if ($requestBody["object"]["status"]["value"] == "CONFIRMED") {
            $order->paymentSuccess();
            $queue = $application
                ->getDbConnection()
                ->fetchAll(
                    "SELECT id FROM sale_atol_queue WHERE order_id = ?",
                    [$requestBody["object"]["orderId"]]
                );
            if (count($queue) > 0) {
                file_put_contents(
                    $_SERVER["DOCUMENT_ROOT"] . "/uploads/logs/vtb.log",
                    date("Y.m.d H:i:s") .
                        " Дублирующийся чек по заказу " .
                        $requestBody["object"]["orderId"] .
                        "\n",
                    FILE_APPEND
                );
            } else {
                $gateway->sendReceiptSell();
            }
        }
        header("HTTP/1.1 200 OK");
        print "OK";
    }
    if ($requestBody["type"] == "REFUND") {
        header("HTTP/1.1 200 OK");
        print "OK";
    }
} catch (\Exception $e) {
    header(
        "HTTP/1.1 500 " . trim(preg_replace("/\s+/", " ", $e->getMessage()))
    );
    print $e->getMessage();
    file_put_contents(
        $_SERVER["DOCUMENT_ROOT"] . "/uploads/logs/vtb.log",
        date("Y.m.d H:i:s") .
                       $e->getMessage() .
                       $e->getLine() .
                        "\n",
        FILE_APPEND
                );
}

/*$data = ob_get_contents();
ob_end_flush();
file_put_contents(__DIR__.'/log'.time().'.txt', $data);*/
