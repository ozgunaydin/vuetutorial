<?php namespace App\Console\Commands\Agi;

use App\Models\Customer\CustomerDIDForward;
use App\Models\Customer\CustomerDID;
use App\Models\Customer\CustomerContact;
use App\Models\Customer\CustomerForward;
use App\Models\Customer\CustomerExtension;
use App\Console\Commands\Agi\Lib\AGIActions;
use GuzzleHttp;
use App\Console\Commands\Agi\AGIXML;
use App\Models\Customer\CustomerIVR;

class IVR
{
    private $fastagi;
    private $data;
    private $funcs = ["playback", "background", "read", "dial", "gotop", "goparent", "queue", "forward_out", "forward_ip", "voicemail", "forward_conference", 'remote_request', 'forward_ivr'];
    private $agiactions;
    private $lastDatas = [];
    private $customer_did;
    private $customerID;

    public function __construct($fastagi, $data, $customer_did)
    {
        $this->agiactions = new AGIActions($fastagi);
        $this->fastagi = $fastagi;
        $this->lang = $data["lang"];
        $this->data = json_decode($data["data"], true);
        // $this->customer_did = $customer_did;
        $this->customerID = $data["customer_id"];
        $this->customer_did = $customer_did;
    }

    public function start()
    {

        $this->fastagi->mylog(" IVR basladi.....");

        $this->fastagi->exec_setlanguage($this->lang);
        foreach ($this->data as $val) {
            if (in_array($val["action"], $this->funcs)) {
                $this->$val["action"]($val);
            }
        }
        if (count($this->data) == 0) {
            $this->fastagi->mylog("Ivrda kayit yok", true);
            $this->fastagi->hangup();
            exit;
        }
        return true;
    }

    private function playback($data)
    {
        $this->fastagi->mylog("IVR Action:  playback  Data:");
        $this->fastagi->mylog($data);

        //saat aralıklarında değilse çalma
        if (isset($data['time']) && $data['time'] != null) {
            $times = explode('-', trim($data['time']));
            if (is_array($times)) {
                $customerTime = strtotime($this->customer_did->customer->current_date_time->format('H:i'));

                if ($customerTime < strtotime($times[0]) || $customerTime > strtotime($times[1])) {
                    $this->fastagi->mylog("Zaman uymuyor");

                    return true;
                }
            }
        }
        $this->fastagi->mylog("IVR Action:  playback  Data:");
        $this->fastagi->mylog($data);

        $sound = $this->getSound($data["sound"]);

        $this->fastagi->exec("Playback", $sound);
    }

    private function forward_ivr($data)
    {
        $this->fastagi->mylog("IVR Action:  forward_ivr  Data:");
        $this->fastagi->mylog($data);
        $this->fastagi->mylog("Forward IVR  IVR ID {$data['customer_ivr_id']}");

        $customerIVR = CustomerIVR::select([
            'customer_ivr.id as id',
            'customer_ivr.name as name',
            'customer_ivr.data as data',
            'customer_ivr.lang as lang',
            'customer_ivr.customer_id as customer_id'
        ])->where('customer_ivr.id', $data['customer_ivr_id'])->first();

        if ($customerIVR) {

            $_ivr = new IVR($this->fastagi, $customerIVR, $this->customer_did);
            $_ivr->start();
        } else {
            $this->fastagi->mylog(" ivr not found", true);

        }
        return true;


    }

    private function forward_ip($data)
    {
        $this->fastagi->mylog("IVR Action:  forward_ip  Data:");
        $this->fastagi->mylog($data);


        $calle_number = $data["number"] != null ? $data["number"] : str_replace('+', '', $this->fastagi->request['agi_callerid']);
        $data["caller_id"] = $data["caller_id"] != null ? $data["caller_id"] : str_replace('+', '', $this->fastagi->request['agi_callerid']);
        $this->agiactions->ipForward($data['ip'], $calle_number, $data["caller_id"]);
        return true;

    }

    private function forward_out($data)
    {
        $this->fastagi->mylog("IVR Action:  forward_out  Data:");
        $this->fastagi->mylog($data);
        $customerDid = CustomerDID::with(['did', 'customer'])
            ->where('customer_id', $this->customerID)
            ->where('status', 'active')
            ->find(isset($data['customer_did_id']) ? $data['customer_did_id'] : 0);
        if (!$customerDid) {
            // DID kullanımda değil
            $this->fastagi->exec("Playback", "outofuse");
            $this->fastagi->mylog(" did not found");
            $this->fastagi->hangup();
            exit;
        }
        $this->fastagi->mylog(" Customer DID :  {$customerDid->customer_did_id}");
        $this->fastagi->mylog("ring count {$data['ring_count']} ");
        $this->fastagi->exec('Set', "CDR(route)=i2o");
        return $this->agiactions->callOutgoing($customerDid, $data["number"], ['ring_count' => $data['ring_count'], 'outgoing-forward' => true]);
    }

    private function read($data)
    {
        $this->fastagi->mylog("IVR Action:  read  Data:");
        $this->fastagi->mylog($data);

        $this->fastagi->mylog(" read basladi {$data['repeat_count']} kez tekrar edecek..");
        for ($repeat = 1; $repeat <= $data["repeat_count"]; $repeat++) {

            $this->fastagi->mylog(" read... {$repeat}. tekrar.. ");

            $rand_id = rand(1, 10000);
            if ($data["wait_exten"]) {
                $this->fastagi->mylog(" dahili bekle");
                $data["digit"] = '';
            }
            $sound = $this->getSound($data["sound"]);

            $this->fastagi->exec("Read",
                "IVRDATA{$rand_id},{$sound},{$data["digit"]},,{$data["repeat_count"]},{$data["timeout"]}");
            $read_data = $this->fastagi->get_variable("IVRDATA{$rand_id}");
            $read_data = $read_data['data'];
            if ($data["wait_exten"] && strlen($read_data) >= 3 && strlen($read_data) <= 5) {
                // extension

                $calleeUser = CustomerExtension::with('customer')->where('name', $this->customerID . "*" . $read_data)->first();
                $did_number = $this->fastagi->request['agi_dnid'];

                $callerUser = CustomerExtension::with('customer')->where('name', $this->customerID . "*9999")->first();

                if ($callerUser) {
                    $this->agiactions->callCustomerCallPlan($read_data, $callerUser);
                } else {
                    $this->fastagi->mylog("NO CALLER USER " . $this->customerID . "*9999");
                }

                $this->fastagi->mylog(" calling extension {$calleeUser->name} ");
                $this->agiactions->callOutgoingToExtension($did_number, $calleeUser);

                $this->fastagi->hangup();
                exit();

            }

            array_push($this->lastDatas, $data);


            for ($i = 0; $i < count($data["data"]); $i++) {

                $this->fastagi->mylog(" data : {$data["data"][$i]["action"]}");

                if ($data["data"][$i]["key"] == $read_data) {

                    if (in_array($data["data"][$i]["action"], $this->funcs)) {

                        $this->$data["data"][$i]["action"]($data["data"][$i]);
                    }

                }
            }
            if (strlen($read_data) == 0) {
                $this->fastagi->mylog(" tuslama yapilmadi..");
                //  $this->fastagi->exec("Playback", 'privacy-incorrect');
            }
        }
        return true;
    }

    private function background($data)
    {
        $this->fastagi->mylog("IVR Action:  background  Data:");
        $this->fastagi->mylog($data);

        $repeat = 0;

        do {
            $this->fastagi->mylog(" background");
            $rand_id = rand(1, 10000);


            $sound = $this->getSound($data["sound"]);

            $this->fastagi->exec("Read", "BGDATA{$rand_id},{$sound},,,,");
            $read_extension = $this->fastagi->get_variable("BGDATA{$rand_id}");
            $read_extension = $read_extension['data'];
            if ($read_extension == null) {
                return true;
            }
            $this->fastagi->mylog(" read_extension {$read_extension}");

            $calleeUser = CustomerExtension::with('customer')->where('name', $this->customerID . "*" . $read_extension)->first();
            $did_number = $this->fastagi->request['agi_dnid'];
            $this->fastagi->mylog(" calling extension {  $calleeUser->name} ");
            if (!$this->agiactions->callOutgoingToExtension($did_number, $calleeUser)) {
                $repeat = 1;
            }

        } while ($repeat > 0);

    }

    private function voicemail($data)
    {
        $this->fastagi->mylog("IVR Action:  voicemail  Data:");
        $this->fastagi->mylog($data);

        $customerUser = CustomerExtension::find($data["number"]);
        $callee_Exten = $customerUser->customer_id . '*' . $customerUser->name;
        $this->fastagi->mylog(" voicemail {$callee_Exten}");
        $sayMessage = isset($data['say_message']) && $data['say_message'] ? true : false;
        $this->agiactions->redirectVoicemail($customerUser, $sayMessage);

    }

    private function queue($data)
    {

        $this->fastagi->mylog("IVR Action:  queue  Data:");
        $this->fastagi->mylog($data);

        $this->agiactions->callQueue($data["queue"], ['wait_block' => isset($data["wait_block"]) ? $data["wait_block"] : false]);
    }

    private function forward_conference($data)
    {
        $this->fastagi->mylog("IVR Action:  forward_conference  Data:");
        $this->fastagi->mylog($data);
        $this->fastagi->mylog("mylog >> conference {$data["conference"]}");

        if (isset($data['dynamic_room_sound']) && $data['dynamic_room_sound']) {
            $this->fastagi->mylog(" dynamic conferance room");
            $sound = $this->getSound($data["sound"]);


            $this->fastagi->exec("Read",
                "DYNAMICROOM,{$sound},,,3,5");
            $read_data = $this->fastagi->get_variable("DYNAMICROOM");
            $read_data = $read_data['data'];
            if (strlen($read_data) == 0) {
                $this->fastagi->mylog(" tuslama yapilmadi..");
            } else {

                $this->fastagi->exec("ConfBridge", "{$read_data},default_bridge,default_user");
            }


        } else {
            $this->agiactions->callConference($data["conference"]);
        }

    }

    private function gotop()
    {

        $this->fastagi->mylog("IVR Action:  gotop");

        $this->lastDatas = [];
        foreach ($this->data as $val) {
            $this->fastagi->mylog(" action : {$val["action"]}");
            if (in_array($val["action"], $this->funcs)) {

                $this->$val["action"]($val);
            }
        }

    }

    private function goparent()
    {

        $this->fastagi->mylog("IVR Action:  goparent");

        $lastData = array_pop($this->lastDatas);
        $lastData = array_pop($this->lastDatas);
        if (in_array($lastData["action"], $this->funcs)) {
            $this->fastagi->mylog(" QWEQWEQWE : {$lastData["action"]}");
            $this->$lastData["action"]($lastData);
        } else {
            $this->lastDatas = [];
            foreach ($this->data as $val) {
                $this->fastagi->mylog(" action : {$val["action"]}");
                if (in_array($val["action"], $this->funcs)) {

                    $this->$val["action"]($val);
                }
            }
        }
    }

    private function dial($data)
    {
        $this->fastagi->mylog("IVR Action:  dial  Data:");
        $this->fastagi->mylog($data);

        $customerUser = CustomerExtension::find($data["number"]);
        $this->fastagi->mylog(" ring count {$data['ring_count']} ");
        $did_number = $this->fastagi->request['agi_dnid'];
        $this->fastagi->mylog(" calling extension ");
        return $this->agiactions->callOutgoingToExtension($did_number, $customerUser, ['ring_count' => $data['ring_count']]);


    }

    private function remote_request($data)
    {
        $this->fastagi->mylog("IVR Action:  remote_request  Data:");
        $this->fastagi->mylog($data);
        $url = $data['api_url'];
        $request_timeout = $data['remote_request_timeout'];
        $request_method = $data['method'];
        $gathers = [];
        foreach ($data['data'] as $key => $read) {
            $this->fastagi->mylog("        {$key}. gather");
            $this->fastagi->mylog($read);

            for ($repeat = 1; $repeat <= $read["repeat_count"]; $repeat++) {

                $this->fastagi->mylog(" gather... {$repeat}. tekrar.. ");

                $rand_id = rand(1, 10000);

                $sound = $this->getSound($read["sound"]);

                $this->fastagi->exec("Read",
                    "IVRDATA{$rand_id},{$sound},{$read["digit"]},,{$read["repeat_count"]},{$read["timeout"]}");
                $read_data = $this->fastagi->get_variable("IVRDATA{$rand_id}");
                $read_data = $read_data['data'];
                if (strlen($read_data) == 0) {
                    $this->fastagi->mylog(" tuslama yapilmadi..");
                    //  $this->fastagi->exec("Playback", 'privacy-incorrect');
                } else {
                    $gathers[] = $read_data;
                    $repeat = $read['repeat_count'];
                }
            }

        }
        $caller_number = $this->fastagi->request['agi_callerid'];
        $callee_number = $this->fastagi->request['agi_dnid'];
        $client = new GuzzleHttp\Client;
        try {
            $promise = $client->request($request_method, $url, [
                'http_errors' => true,
                'debug'       => false,
                'timeout'     => $request_timeout,
                'form_params' => ['caller_number' => $caller_number, 'callee_number' => $callee_number, 'data' => $gathers]
            ]);

            $body = $promise->getBody()->getContents();

        } catch (RequestException $e) {
            $this->fastagi->mylog('Xml request de cevap alinamadi', true);
            //todo :// hata mesajı dinlet

        }
//todo :check xml!!
        $this->fastagi->mylog('alinan cevap' . $body);

        $xml = new AGIXML($this->fastagi, $body, $this->customerID, $this->customer_did);
        $xml->start();
    }

    private function getSound($data_sound)
    {

        if (preg_match('/^(generic:)/', $data_sound)) {
            return $this->lang . '/' . str_replace('generic:', '', $data_sound);
        } else {
            return "ivr/{$this->customerID}/{$data_sound}";
        }
    }
}
