<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SMSController extends Controller
{
    protected $endPoint;
    protected $apiKey;
    protected $senderID;

    public function __construct()
    {
        $this->endPoint = config('app.mnotify_api_url');
        $this->apiKey = config('app.mnotify_api_key');
        $this->senderID = config('app.mnotify_sender_id');
    }

    public function sendMessage($recipients, $message)
    {
        $url = $this->endPoint . '?key=' . $this->apiKey;
        
        $query = [
            'recipient' => $recipients,
            'sender' => $this->senderID,
            'message' => $message,
            'is_schedule' => 'false',
            'schedule_date' => ''
        ];
        
        $ch = curl_init();

        $headers = array();
        $headers[] = "Content-Type: application/json";

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $result = curl_exec($ch);
        
        curl_close($ch);
        
        return $result;
    }
}
