<?php
namespace SalePaymentVTB;

class Gateway extends \Sale\PaymentGateway\GatewayAtol
{
    const GATEWAY_PRODUCTION = "https://pay.raif.ru";
    const GATEWAY_TEST = " https://hackaton.bankingapi.ru/api/smb/efcp/e-commerce/api/";

    public static function getInfo2()
    {
        return [
            "name" => "VTB",
            "description" => "",
            "icon" => "/plugins/sale-payment-vtb/images/icon.png",
            "params" => [
                [
                    "name" => "client_id",
                    "xtype" => "textfield",
                    "fieldLabel" => "Идентификатор партнёра*",
                    "allowBlank" => false,
                ],
                [
                    "name" => "secretKey",
                    "xtype" => "textfield",
                    "fieldLabel" => "Секретный ключ",
                    "allowBlank" => false,
                ],
                [
                    "name" => "returnURL",
                    "xtype" => "textfield",
                    "fieldLabel" => "Страница после совершения платежа",
                    "allowBlank" => false,
                ],
                [
                    "xtype" => "checkbox",
                    "name" => "test_mode",
                    "boxLabel" => "тестовый режим",
                    "inputValue" => 1,
                    "uncheckeDvalue" => 0,
                ],
                [
                    "name" => "callbackUrl",
                    "xtype" => "displayfield",
                    "fieldLabel" => "URL-адрес для callback уведомлений",
                    "value" =>
                        "//" .
                        $_SERVER["HTTP_HOST"] .
                        "/cms/plugins/sale-payment-vtb/callback.php",
                ],
            ],
        ];
    }

    public function pay($return = "", $fail = "")
    {
        if (!$return) {
            $return = \Cetera\Application::getInstance()
                ->getServer()
                ->getFullUrl();
        }
        if (!$fail) {
            $fail = \Cetera\Application::getInstance()
                ->getServer()
                ->getFullUrl();
        }
        try {
            $json = [
                "orderId" => $this->order->id,
                "orderName" => "Заказ " . $this->order->id,
                "customer" => [
                    "email" => $this->order->getEmail(),
                ],
                "amount" => [
                    "value" => $this->order->getTotal(),
                    "code" => "RUB",
                ],
            ];
            if (getenv("RUN_MODE", true) === "development") {
                $url = self::GATEWAY_TEST;
            } else {
                $url =
                    isset($this->params["test_mode"]) &&
                    $this->params["test_mode"]
                        ? self::GATEWAY_TEST
                        : self::GATEWAY_PRODUCTION;
            }
            $token = $this->getAccessToken($this->params["client_id"],$this->params["secretKey"]);
            $client = new \GuzzleHttp\Client();
            $response = $client->request("POST", $url . "/v1/orders/", [
                "json" => $json,
                "headers" => [
                    "Authorization" => "Bearer " . $token,
                    "X-IBM-Client-Id" =>$this->params["client_id"],
                ],
            ]);

            $res = json_decode($response->getBody(), true);
            $id = $res["object"]["orderId"];
            $amount = $res["object"]["amount"]["value"];
            $payUrl = $res["object"]["payUrl"];

            if (
                isset($payUrlt) &&
                !empty($payUrl) &&
                isset($amount) &&
                !empty($amount)
            ) {
                header("Location: " . $payUrl);
                $data = [
                    "order" => $this->order->id,
                    "amount" => $amount,
                ];
                $this->saveTransaction($id, $data);
            }

            die();
        } catch (\Exception $e) {
            $response = $e;
            file_put_contents(
                $_SERVER["DOCUMENT_ROOT"] . "/uploads/logs/vtb.log",
                date("Y.m.d H:i:s") .
                    " " .
                    $_SERVER["QUERY_STRING"] .
                    " " .
                    $e->getMessage() .
                    $e->getFile() .
                    $e->getLine() .
                    $e->getTraceAsString() .
                    "\n",
                FILE_APPEND
            );
        }
    }

    public static function isRefundAllowed()
    {
        return true;
    }
    public function getAccessToken($clientId, $clientSecret)
    {
        $url =
            "https://auth.bankingapi.ru/auth/realms/kubernetes/protocol/openid-connect/token";

        $postData = http_build_query([
            "grant_type" => "client_credentials",
            "client_id" => $clientId,
            "client_secret" => $clientSecret,
        ]);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/x-www-form-urlencoded",
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("cURL error: " . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $responseData = json_decode($response, true);
        if ($httpCode !== 200 || !isset($responseData["access_token"])) {
            throw new Exception(
                "Failed to get access token. HTTP Code: " .
                    $httpCode .
                    ", Response: " .
                    $response
            );
        }
        return $responseData["access_token"];
    }
    public function refund($items = null)
    {
        try {
            $refundId = "refund" . $this->order->id;
            $application->connectDb();
            $paymentId = $application
                ->getDbConnection()
                ->fetchColumn(
                    "SELECT transaction_id FROM sale_payment_transactions WHERE order_id=?",
                    [$this->order->id]
                );
            $params = [
                "refundId" => $refundId,
                "paymentId" => $paymentId,
                "amount" => [
                    "value" => $this->order->getTotal(),
                    "code" => "RUB",
                ],
            ];
            if ($items !== null) {
                $amount = 0;
                foreach ($items as $key => $item) {
                    if ($item["quantity_refund"] <= 0) {
                        continue;
                    }
                    $amount +=
                        intval($item["quantity_refund"]) * $item["price"];
                }
                $params["amount"]["value"] = $amount;
            }

            //print_r($params);
            //return;

            if (getenv("RUN_MODE", true) === "development") {
                $url = self::GATEWAY_TEST;
            } else {
                $url =
                    isset($this->params["test_mode"]) &&
                    $this->params["test_mode"]
                        ? self::GATEWAY_TEST
                        : self::GATEWAY_PRODUCTION;
            }
            $token = $this->getAccessToken($this->params["client_id"],$this->params["secretKey"]);
            $client = new \GuzzleHttp\Client();
            $response = $client->request("POST", $url . "/v1/refunds", [
                "verify" => false,
                "json" => $params,
                "headers" => [
                    "Authorization" => "Bearer " . $token,
                    "X-IBM-Client-Id" =>$this->params["client_id"],
                ],
            ]);

            $res = json_decode($response->getBody(), true);

            if (
                isset($res["object"]["status"]["value"]) &&
                $res["object"]["status"]["value"] == "NEW"
            ) {
                $this->saveTransaction($refundId, $res);
                $res = $this->sendReceiptRefund($items);
                return;
            } else {
                throw new \Exception($res["code"] . ": " . $res["message"]);
            }
        } catch (\Exception $e) {
            $response = $e;
            file_put_contents(
                $_SERVER["DOCUMENT_ROOT"] . "/uploads/logs/vtb.log",
                date("Y.m.d H:i:s") .
                    " " .
                    $_SERVER["QUERY_STRING"] .
                    " " .
                    $e->getMessage() .
                    $e->getFile() .
                    $e->getLine() .
                    $e->getTraceAsString() .
                    "\n",
                FILE_APPEND
            );
        }
    }
}
