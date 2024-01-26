<?php

namespace App\Http\Controllers;

use OdooClient\Client;
use Illuminate\Support\Facades\Log;

class OdooPOS extends Controller
{
    protected $client;
    protected $currency;

    public function __construct()
    {
        $url = config('app.odoo_url');
        $database = config('app.odoo_db', '');
        $user = config('app.odoo_username');
        $password = config('app.odoo_password');
        $this->client = new Client($url, $database, $user, $password);
        $this->currency = config('app.odoowoo_currency');
    }

    public function getDailySalesReport($recipients, $date)
    {
        $fields = array(
            'id',
            'name',
            'start_at',
            'cash_register_difference',
            'config_id',
            'total_payments_amount',
            'order_count'
        );

        $criteria = array(
            array('start_at', '>', date($date . " 00:00:00")),
            array('stop_at', '<', date($date . " 23:59:59")),
            array('state', '=', 'closed')
        );

        try {
            $sessions = $this->client->search_read('pos.session', $criteria, $fields);
        } catch (\Throwable $th) {
            throw $th;
        }

        $_sessions = [];
        if (!empty($sessions)) {
            $j = 0;
            foreach ($sessions as $session) {
                if ($session['total_payments_amount'] > 0) {
                    $fields = array(
                        'id',
                        'payment_method_id',
                        'amount'
                    );

                    $criteria = array(
                        array('session_id', '=', $session['id'])
                    );

                    try {
                        $payments = $this->client->search_read('pos.payment', $criteria, $fields);
                    } catch (\Throwable $th) {
                        throw $th;
                    }

                    $_sessions[$j]['name'] = $this->formatText($session['config_id'][1]);
                    $_sessions[$j]['ref'] = $this->formatText($session['name']);
                    $_sessions[$j]['payment_methods'] = [];

                    foreach ($payments as $payment) {
                        $index = $payment['payment_method_id'][1];
                        $value = $payment['amount'];
                        if (!isset($_sessions[$j]['payment_methods'][$index])) {
                            $_sessions[$j]['payment_methods'][$index] = $value;
                        } else {
                            $_sessions[$j]['payment_methods'][$index] += $value;
                        }
                    }
                    $_sessions[$j]['count'] = $session['order_count'];
                    $_sessions[$j]['total'] = $session['total_payments_amount'];
                    $j++;
                }
            }
        }

        $messages = [];
        if (!empty($_sessions)) {
            foreach ($_sessions as $_session) {
                $messages[] = $this->messageTemplate1($_session);
            }
        }

        if (!empty($messages)) {
            foreach ($messages as $message) {
                $smsController = new SMSController();
                $response = $smsController->sendMessage($recipients, $message);
                Log::info($response);
            }
        }
    }

    public function getDailyCustomers($date)
    {
        $fields = array(
            'id'
        );

        $criteria = array(
            array('start_at', '>', date($date . " 00:00:00")),
            array('stop_at', '<', date($date . " 23:59:59")),
            array('state', '=', 'closed')
        );

        try {
            $sessions = $this->client->search_read('pos.session', $criteria, $fields);
        } catch (\Throwable $th) {
            throw $th;
        }

        $orders = [];
        if (!empty($sessions)) {
            foreach ($sessions as $session) {
                sleep($this->odooSleepSeconds());
                $fields = array(
                    'id',
                    'partner_id'
                );

                $criteria = array(
                    array('session_id', '=', $session['id']),
                    array('state', '=', 'done')
                );

                try {
                    $orders = $this->client->search_read('pos.order', $criteria, $fields);
                } catch (\Throwable $th) {
                    throw $th;
                }
            }
        }

        $customers = [];
        if (!empty($orders)) {
            foreach ($orders as $order) {
                if ($order['partner_id']) {
                    sleep($this->odooSleepSeconds());
                    $fields = array(
                        'id',
                        'phone',
                        'name'
                    );

                    $criteria = array(
                        array('id', '=', $order['partner_id'][0]),
                    );

                    try {
                        $customers[] = $this->client->search_read('res.partner', $criteria, $fields)[0];
                    } catch (\Throwable $th) {
                        throw $th;
                    }
                }
            }
        }

        $customers = array_column($customers, null, 'phone');
        $customers = array_values($customers);
        $customers = array_filter($customers, function($value) {
            return !empty($value['phone']);
        });

        $messages = [];
        if (!empty($customers)) {
            foreach ($customers as $customer) {
                $messages[] = array(
                    'recipient' => $customer['phone'],
                    'message' => $this->messageTemplate2($customer)
                );
            }
        }

        if (!empty($messages)) {
            foreach ($messages as $message) {
                $smsController = new SMSController();
                // $response = $smsController->sendMessage(array($message['recipient']), $message['message']);
                $response = $smsController->sendMessage(array('0558181935'), $message['message']);
                Log::info($response);
                break;
            }
        }
    }

    private function formatName($inputString)
    {
        // Use a regular expression to remove numbers and punctuation
        $cleanedString = explode(' ', preg_replace('/[0-9\p{P}]/u', '', $inputString));
        return trim($cleanedString[0]);
    }

    private function formatText($input)
    {
        // Remove text within brackets and the brackets themselves
        $output = preg_replace('/\([^)]*\)/', '', $input);

        // Remove double spaces
        $output = preg_replace('/\s+/', ' ', $output);

        return trim($output);
    }

    private function messageTemplate1($data)
    {
        $message = $data['name'] . " ";
        $message .= "(" . $data['ref'] . ") on ";
        $message .= date("d M Y") . " ";
        $message .= "Report:\n";
        $message .= "Orders: " . $data['count'] . "\n";
        foreach ($data['payment_methods'] as $key => $value) {
            $message .= $key . ": " . $this->currency . " " . number_format($value, 2) . "\n";
        }
        $message .= "Total: " . $this->currency . " " . number_format($data['total'], 2);
        return $message;
    }

    private function messageTemplate2($data)
    {
        $message = config('app.odoowoo_customer_sms_template_1');
        $message = str_replace('[name]', ucwords(strtolower($this->formatName($data['name']))), $message);
        $message = str_replace('[company]', config('app.odoowoo_company'), $message);
        return $message;
    }
}
