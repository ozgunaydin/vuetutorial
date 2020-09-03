<?php namespace App\Console\Commands\Agi\Lib;


use App\Models\Customer\CustomerContactForward;
use App\Models\Customer\CustomerDIDForward;
use App\Models\Customer\CustomerContact;
use App\Models\Customer\CustomerForward;
use App\Models\Customer\CustomerDID;
use App\Models\Customer\CustomerExtension;
use App\Models\Customer\CustomerQueue;
use App\Models\Customer\CustomerConfbridge;
use App\Models\Customer\CustomerQueueMembers;
use App\Models\Customer\CustomerVoicemail;
use App\Models\Customer\Fax\FaxInbox;
use App\Models\Customer\Fax\FaxRight;
use App\Models\Customer\Fax\FaxInboxUsers;
use App\Models\Customer\Fax\FaxUser;
use App\Models\Emergency;
use App\Models\Trunk;
use App\Rate;
use App\Models\Billing\Billing;
use App\Console\Commands\Agi\Lib\AGIBilling;
use App\Console\Commands\Agi\Lib\AGIHelper;
use App\Models\DialPlan;
use App\Services\MobileNotification;
use App\Models\Customer\CustomerCallPlanIncoming;

class AGIActions
{
    public $fastagi;
    private $ring_count;
    private $agibilling;


    public function __construct($fastagi)
    {
        $this->fastagi = $fastagi;
        $this->ring_count = 30;
    }

    #### CALL FUNCTIONS
    public function callExtensionToExtension($dnid, $callerUser, $params = [])
    {


        if (AGIHelper::checkCallLimit($this->fastagi, $callerUser->customer) == false) {
            return true;
        }

        $caller_number = $callerUser->customer_id . "*" . $callerUser->name;
        if (!AGIHelper::checkCallPermission($callerUser, 'extension')) {
            $this->fastagi->mylog("no dial permission for extension", true);
            $this->fastagi->exec("Playback", "nodialpermission");
            $this->fastagi->exec("NoCDR", "");

            return true;
        }


        // callee user
        //Todo Cache!
        $calleeUser = CustomerExtension::with('customer')->where('name', $dnid)->first();

        if (!$calleeUser) {


            //acil durum numarası arama
            if (strlen($this->fastagi->request['agi_extension']) <= 3) {
                $emergency = Emergency::where('exten', $this->fastagi->request['agi_extension'])->first();
                if ($emergency) {
                    $this->fastagi->mylog(" emergency number {$emergency->exten}");

                    $this->callExtensionToOutgoing($this->fastagi->request['agi_extension'], $callerUser,
                        ['emergency' => true]);

                    return true;
                }
            }

            $this->fastagi->exec("Playback", "invalid");
            $this->fastagi->mylog("calleeUser bulunamadi.", true);
            $this->fastagi->exec("NoCDR", "");

            return true;
        }

        //CallerUser bilgisi CDRe ekleniyor

        //if (isset($params['forwarding'])) {

        $this->fastagi->exec('Set', "CDR(src_customer_extension_id)={$callerUser->id}");
        $this->fastagi->exec('Set', "CDR(src_user_id)={$callerUser->web_user_id}");
        $this->fastagi->exec('Set', "CDR(dst_customer_extension_id)={$calleeUser->id}");
        $this->fastagi->exec('Set', "CDR(dst_user_id)={$calleeUser->web_user_id}");


        $this->fastagi->exec('Set', "CDR(customer_id)={$callerUser->customer_id}");
        $this->fastagi->exec('Set', "CDR(server_id)={$callerUser->regserver}");
        $this->fastagi->exec('Set', "CDR(reseller_id)={$callerUser->customer->reseller_id}");


        $this->checkMonitor($callerUser, $calleeUser, "extension");
        $this->checkCustomerExtensionDnd($calleeUser);


        if (in_array('no-forward', $params)) {
            $this->fastagi->mylog("forwarda bakilmaksizin arama yapiliyor");
            $this->callExtension($calleeUser, 30);

        } else {

            //Aranan sipin yönlendirmesi varsa bu fonksiyonda tespit edilip arama yapılıyor
            $forward = $this->callCustomerExtensionForward($calleeUser);

            if (!$forward) {
                $this->fastagi->mylog("Herhangi bir yonlendirme tanimlanmamis");
                $this->callExtension($calleeUser, 30);

            }
        }

        $this->redirectVoicemail($calleeUser);

        return true;
    }

    public function callExtension($calleExtension, $ringDuration = 30, $redirectVoicemail = true, $params = [])
    {

        //Musiconhold
        if ($calleExtension->musiconhold != null) {
            $this->fastagi->exec('Set', "CHANNEL(musicclass)={$calleExtension->musiconhold}");
        }
        $dnid = $calleExtension->customer_id . "*" . $calleExtension->name;
        $deviceState = AGIHelper::getExtensionDeviceState($this->fastagi, $dnid);

        if ($calleExtension->mobile_mac != null && $calleExtension->device_token != null) {
            $this->fastagi->mylog("Mobil cihaza bildirim gonderiliyor");
            $this->fastagi->exec('Ringing', '');
            //todo :: asenkron çalışacak şekilde ayarlanmalı
            event(MobileNotification::send($calleExtension->device_os, $calleExtension->device_token, ['data' => ['action' => 'call',]]));

            for ($i = 1; $i < 10; $i++) {
                $this->fastagi->mylog(" Registration Deneme:{$i}");
                sleep(1);
                if (AGIHelper::getExtensionDeviceState($this->fastagi, $dnid) != 'UNAVAILABLE') {
                    break;
                }
            }


            $deviceState = AGIHelper::getExtensionDeviceState($this->fastagi, $dnid);
        }
        $dnidStr = "SIP/{$dnid}";
        if ($calleExtension->ringgroup != null) {
            $groupExtensions = explode(',', $calleExtension->ringgroup);

            foreach ($groupExtensions as $groupExtension) {
                $dnidStr .= "&SIP/{$groupExtension}";
            }

            $this->fastagi->mylog(['Ring Group extensions' => $dnidStr]);
            $this->fastagi->exec("Dial", "{$dnidStr},{$ringDuration},TtKk");
            return true;
        } else {

            if ($deviceState == 'NOT_INUSE' || $deviceState == '') {
                $this->fastagi->exec("Dial", "{$dnidStr},{$ringDuration},TtKk");
            } elseif ($deviceState == 'INUSE' || $deviceState == 'BUSY') {
                if (!isset($params['disable-busy-call'])) { //Meşgul durumu için yonlendirme tanimlanmis ise arama yapilmadan yönlendirme yapilicak
                    $this->fastagi->exec("Playback", "extensionbusy");
                    $this->fastagi->exec("Playback", "pls_wait");
                    $this->fastagi->exec("Dial", "{$dnidStr},{$ringDuration},TtKk");
                } else {
                    $this->fastagi->mylog('disable-busy-call aktif');
                }
            } else {
                $this->fastagi->mylog('Dahili telefon register degil arama yapilamiyor');
            }
        }
        if ($redirectVoicemail) {
            $this->redirectVoicemail($calleExtension);
        }
        return true;
    }

    public function callTrunkToExtension($caller_number, $did_number)
    {
        //Dahili araması mı kontrol !
        $customerExtension = CustomerExtension::with('customer')->where('name', $did_number)->first();
        if ($customerExtension) {
            $this->fastagi->mylog("Trunk uzerinden dahili aramasi yapiliyor");
            $callerid = str_replace('+', '', $caller_number);
            //Set Customer Info
            $this->setCustomerInfo($customerExtension->customer);

            $callerid = $this->getCallerIDFromContacts($customerExtension->customer, $callerid);


            $this->fastagi->mylog("           Arayan Kisi Bilgisi");
            $this->fastagi->mylog($callerid);

            $callerIDName = $callerid['name'];

            $this->fastagi->exec("Set", "CALLERID(name)={$callerIDName}");
            $this->fastagi->exec("Set", "CALLERID(num)={$callerid['phone']}");


            $this->fastagi->exec_setlanguage($customerExtension->customer->pbx_lang);
            $this->fastagi->exec('Set', "CDR(customer_id)=" . $customerExtension->customer->id);
            $this->fastagi->exec('Set', "CDR(reseller_id)=" . $customerExtension->customer->reseller_id);


            $this->callOutgoingToExtension($callerid, $customerExtension);
            exit;
        }
        return true;
    }

    public function callOutgoingToExtension($caller_number, $calleeUser, $params = [])
    {
        if (AGIHelper::checkCallLimit($this->fastagi, $calleeUser->customer, 'incoming') == false) {
            return true;
        }
        $callee_Exten = $calleeUser->customer_id . "*" . $calleeUser->name;
        if (!$calleeUser) {
            $this->fastagi->exec("Playback", "invalid");
            $this->fastagi->mylog("calleeUser bulunamadi", true);
            exit;

        }

        $this->fastagi->exec('Set', "CDR(route)=o2e");
        $this->fastagi->exec('Set', "CDR(dst_customer_extension_id)=" . $calleeUser->id);
        $this->fastagi->exec('Set', "CDR(reseller_id)=" . $calleeUser->customer->reseller_id);
        $this->fastagi->exec('Set', "CDR(server_id)=" . $calleeUser->regserver);


        $this->checkMonitor($calleeUser, false, "incoming");

        $this->checkCustomerExtensionDnd($calleeUser);

        //Aranan sipin yönlendirmesi varsa bu fonksiyonda tespit edilip arama yapılıyor
        $forward = $this->callCustomerExtensionForward($calleeUser);

        if (!$forward) {

            $ring_count = isset($params['ring_count']) ? $params['ring_count'] : $this->ring_count;

            $this->callExtension($calleeUser, $ring_count);


        }


        return true;
    }


    public function callNeighborExtensionToExtension($callee_Exten, $callerUser, $params = [])
    {
        if (AGIHelper::checkCallLimit($this->fastagi, $callerUser->customer) == false) {
            return true;
        }
        $calleeUser = CustomerExtension::with('customer')->where('name', $callee_Exten)->first();

        if (!$calleeUser) {
            $this->fastagi->exec("Playback", "invalid");
            $this->fastagi->mylog("calleeUser bulunamadi", true);
            exit;

        }


        $this->fastagi->exec('Set', "CDR(src_customer_extension_id)=" . $callerUser->id);
        $this->fastagi->exec('Set', "CDR(src_user_id)={$callerUser->web_user_id}");
        $this->fastagi->exec('Set', "CDR(dst_customer_extension_id)=" . $calleeUser->id);
        $this->fastagi->exec('Set', "CDR(dst_user_id)={$calleeUser->web_user_id}");
        $this->fastagi->exec('Set', "CDR(reseller_id)=" . $calleeUser->customer->reseller_id);
        $this->fastagi->exec('Set', "CDR(customer_id)=" . $calleeUser->customer->id);
        $this->fastagi->exec('Set', "CDR(server_id)=" . $calleeUser->regserver);


        $this->checkMonitor($calleeUser, false, "incoming");
        $this->checkCustomerExtensionDnd($calleeUser);


        //Aranan sipin yönlendirmesi varsa bu fonksiyonda tespit edilip arama yapılıyor
        $forward = $this->callCustomerExtensionForward($calleeUser);

        if (!$forward) {

            $ring_count = isset($params['ring_count']) ? $params['ring_count'] : $this->ring_count;

            $this->callExtension($calleeUser, $ring_count);

        }


        return true;

    }


    public function callExtensionToOutgoing($callee_number, $callerUser, $params = [])
    {
        $emergency = isset($params['emergency']) ? $params['emergency'] : false;
        if (isset($params['customer_did_id'])) {
            $customer_did = CustomerDID::with(['did', 'customer'])
                ->where('customer_id', $callerUser->customer_id)
                ->where('status', 'active')
                ->find($params['customer_did_id']);
        } else {
            $customer_did = CustomerDID::with(['did', 'customer'])
                ->where('customer_id', $callerUser->customer_id)
                ->where('status', 'active')
                ->find($callerUser->customer_did_id);
        }
        if (!$customer_did) {

            $this->fastagi->mylog("{$callerUser->name} dahilisi için CustomerDid bulunamadi", true);
            $this->fastagi->exec("Playback", "nodialpermission");
            $this->fastagi->exec("NoCDR", "");

            return true;
        }

        if ($emergency == false) {
            $completed_callee_number = $this->completeNumber($callee_number, [
                'did_country' => $customer_did->did->country,
                'area_code'   => $customer_did->area_code
            ]);
            $right = $this->findCallPattern($completed_callee_number, $customer_did->did->country, $customer_did->area_code);

            $this->fastagi->exec('Set', "CDR(route)={$right}");

            $this->fastagi->mylog("call right : {$right}");
            if (!AGIHelper::checkCallPermission($callerUser, $right)) {

                $this->fastagi->mylog("{$right} arama yetkisi yok", true);
                $this->fastagi->exec("Playback", "nodialpermission");
                $this->fastagi->exec("NoCDR", "");

                return true;
            }


        }


        $this->checkMonitor($callerUser, false, "outgoing");


        $this->fastagi->exec('Set', "CDR(route)={$right}");
        $this->fastagi->exec('Set', "CDR(src_customer_extension_id)={$callerUser->id}");
        $this->fastagi->exec('Set', "CDR(src_user_id)={$callerUser->web_user_id}");
        $this->fastagi->exec('Set', "CDR(server_id)={$callerUser->regserver}");
        $this->fastagi->exec('Set', "CDR(reseller_id)={$callerUser->customer->reseller_id}");

        $params["original_caller_user"] = $callerUser;
        $this->callOutgoing($customer_did, $callee_number, $params);


    }

    public function callOutgoing($customer_did, $callee_number, $params = [])
    {

        $emergency = isset($params['emergency']) ? $params['emergency'] : false;

        if (AGIHelper::checkCallLimit($this->fastagi, $customer_did->customer) == false && $emergency == false) {
            return true;
        }

        $ring_count = isset($params['ring_count']) ? $params['ring_count'] : $this->ring_count;
        $checkNeighborDid = false;

        if (isset($customer_did->customer) && $customer_did->status == "active") {
            if ($emergency == false) {


                $callee_number = $this->completeNumber($callee_number, [
                    'did_country' => $customer_did->did->country,
                    'area_code'   => $customer_did->area_code
                ]);
                $checkNeighborDid = AGIHelper::checkNeighborDid($callee_number);


            } else {
                $this->fastagi->mylog("Acil durum numarasi araniyor.Numara: {$callee_number}");

            }


            if ($checkNeighborDid == true) {
                $this->fastagi->exec('Set', "CDR(route)=neighbor");
                $this->fastagi->exec("Set", "CALLERID(num)={$customer_did->did->did}");
                $this->fastagi->mylog("ARAMA::internaltrunk/{$callee_number}");
                $this->fastagi->exec("Dial",
                    "SIP/internaltrunk/{$callee_number},{$ring_count},Kk");
                return true;
            }


            $trunks = AGIHelper::getTrunks($customer_did, $callee_number, $emergency);
            if (!$trunks) {
                $this->fastagi->mylog("Trunk bulunamadi.", true);
                return true;
            }

            foreach ($trunks as $trunk) {
                $trunkSetting = $trunk['settings'];
                $this->fastagi->mylog(" Trunk {$trunk['trunk']->id} ile arama yapiliyor....");
                $this->fastagi->mylog($trunk['settings']);


                if ($checkNeighborDid == false && $emergency == false) {
                    #Billing
                    $agibilling = new AGIBilling($this->fastagi, $customer_did, $trunk['trunk'], $callee_number);
                    $billingParams = $agibilling->setBilling();
                }


                $lifeTime = isset($billingParams['lifetime']) ? ($billingParams['lifetime'] * 1000) : 0;
                if ($emergency == true) {
                    $lifeTime = 3600;
                }


                $this->fastagi->exec('Set', "CDR(customer_did_id)={$customer_did->id}");
                $this->fastagi->exec('Set', "CDR(customer_id)={$customer_did->customer_id}");


                // check specific callerid ?
                if ($customer_did->callerid) {
                    $callerid = $customer_did->callerid;
                } else {
                    $callerid = $trunkSetting['callerid_prefix'] . $trunkSetting['callerid'];
                }

                //Trunk callerid_trust seçili ise ve arama forward ise arayan bilgisini callerid yap
                if (isset($params['outgoing-forward']) && $trunkSetting['callerid_trust'] == 1) {
                    $getcallerid = $this->fastagi->get_variable('CALLERID(all)');
                    $callerid = $getcallerid['data'];
                    $this->fastagi->mylog(" Arayan calleridsi kullaniliyor.. CallerID:{$callerid} ");
                }

                if (preg_match("/^(0764)/", $callerid)) {
                    if (isset($params["original_caller_user"])) {
                        $this->fastagi->mylog("original_caller_user: " . json_encode($params["original_caller_user"]));
                        if (preg_match("/^(076411)/", $callerid)) {
                            $callerid = substr($callerid, 0, 4) . $params["original_caller_user"]["name"];
                        }else{
                            $callerid .= $params["original_caller_user"]["name"];
                        }
                    }
                }

                $this->fastagi->exec("Set", "CALLERID(num)={$callerid}");

                $this->fastagi->mylog("callOutgoing Dst:{$callee_number}  trunk{$trunk['trunk']->id} Trunk Callerid Prefix : {$trunkSetting['callerid_prefix']} Trunk Dialed Prefix {$trunkSetting['dialed_prefix']}");


                $this->fastagi->mylog(" ARAMA::trunk{$trunk['trunk']->id}/{$trunkSetting['dialed_prefix']}{$callee_number}");
                $this->fastagi->exec("Dial",
                    "SIP/trunk{$trunk['trunk']->id}/{$trunkSetting['dialed_prefix']}{$callee_number},{$ring_count},L({$lifeTime}:30000:15000:0:0:timeleft:vm-goodbye:timeleft)TtKkXx");


                $dialStatus = $this->fastagi->get_variable('DIALSTATUS');
                $dialStatus = $dialStatus['data'];
                $this->fastagi->mylog("Dial Status: {$dialStatus}");
                if (!in_array($dialStatus, ['CONGESTION', 'CHANUNAVAIL'])) {
                    break;
                }


            }

        } else {
            if ($customer_did) {
                $this->fastagi->mylog("callOutgoing  Customer DID {$customer_did->id} Status {$customer_did->status}");
            } else {
                $this->fastagi->mylog("callOutgoing  Customer DID not found");
            }
            $this->fastagi->mylog("callOutgoing  Customer DID not found");
            //todo:: geçersiz customer did uyarı sesi
            $this->fastagi->exec("Playback", "invalid");
        }

        return true;
    }


    public function callCustomerExtensionForward($calleeUser)
    {
        $calleeUser_id = $calleeUser->id;
        $dnid = $calleeUser->customer_id . "*" . $calleeUser->name;


        $customerUserForward = $this->getCustomerExtensionForward($calleeUser_id);
        $ringing_duration = isset($customerUserForward['ringing_duration']) ? $customerUserForward['ringing_duration'] : 30;

        if ($customerUserForward) {
            $this->fastagi->mylog('Extension yonlendirmesi bulundu');
            $this->fastagi->exec('Set', "CDR(route)=e2f");

            $this->fastagi->mylog($customerUserForward);

            if (isset($customerUserForward['always'])) {
                $this->fastagi->mylog('Yonlendirme Tipi: always');
                $this->callNumbersByTurns($customerUserForward['always'], $calleeUser);

                return true;
            }
            $params = [];
            if (isset($customerUserForward['busy'])) {
                $params['disable-busy-call'] = true;
            }

            $this->callExtension($calleeUser, $ringing_duration, false, $params);

            $deviceState = AGIHelper::getExtensionDeviceState($this->fastagi, $dnid);


            $dialStatus = $this->fastagi->get_variable('DIALSTATUS');
            $dialStatus = $dialStatus['data'];
            $this->fastagi->mylog("{$dnid} DIAL STATUS {$dialStatus}");

            if (($deviceState == 'INUSE' || $deviceState == 'BUSY' || $dialStatus == "BUSY") && $customerUserForward['busy']) {
                $this->fastagi->mylog('Yonlendirme Tipi: busy');
                $this->callNumbersByTurns($customerUserForward['busy'], $calleeUser);
            }
            if ($dialStatus == "NOANSWER" && $customerUserForward['unanswered']) {
                $this->fastagi->mylog('Yonlendirme Tipi: unanswered');
                $this->callNumbersByTurns($customerUserForward['unanswered'], $calleeUser);
            }
            if (($dialStatus == "CHANUNAVAIL" || $deviceState == "UNAVAILABLE") && $customerUserForward['unregistered']) {
                $this->fastagi->mylog('Yonlendirme Tipi: unregistered');
                $this->callNumbersByTurns($customerUserForward['unregistered'], $calleeUser);
            }

            return true;
        }

        return false;

    }

    public function callNumbersByTurns($numbers, $calleeUser)
    {

        foreach ($numbers as $number) {

            if (strlen($number) <= 5) { // dahili numara
                $dnid = $calleeUser->customer_id . "*" . $number;
                $this->fastagi->mylog("callNumbersByTurns: forward to {$dnid}");
                $extension = CustomerExtension::with('customer')->where('name', $dnid)->first();
                $this->callExtension($extension, 30, false, ['disable-busy-call']);
            } else {

                $this->fastagi->mylog("callNumbersByTurns: forward to outgoing {$number}");
                $this->callExtensionToOutgoing($number, $calleeUser, ['outgoing-forward' => true]);

            }
            $this->fastagi->mylog("sonraki yonlendirme numarasi araniyor.");
        }
        $this->fastagi->mylog("hicbir yonlendirme tamamlanamadi");

        return true;
    }

    public function callQueue($id, $params = [])
    {
        $queue = CustomerQueue::find($id);

        $callerid = str_replace('+', '', $this->fastagi->request['agi_callerid']);
        if ($calleeUser = AGIHelper::checkAgentsCallers($this->fastagi, $callerid, $queue)) {
            $this->fastagi->exec('Set', "CDR(route)=o2ac");
            $this->callExtension($calleeUser, 30, false);
            return true;
        }


        $this->fastagi->exec('Answer', '');
        if ($queue) {
            $queue_name = $queue->name;

            $queueActiveMemberCount = CustomerQueueMembers::with('extension')
                ->whereHas('extension', function ($query) {
                    $query->whereNotNull('regserver');
                })
                ->where('customer_queues_id', $queue->id)->count();

            if (isset($params['wait_block']) && $params['wait_block']) {

                $queueFreeAgent = $this->fastagi->get_variable("QUEUE_MEMBER({$queue_name},free)");
                $queueFreeAgent = $queueFreeAgent['data'];
                $this->fastagi->mylog("Bostaki Agent sayisi: {$queueFreeAgent}");
                if ($queueFreeAgent == 0) {
                    $this->fastagi->mylog("Wait Block Aktif. Bostaki Agent sayisi: {$queueFreeAgent}");
                    return true;
                }
            }

            //Musiconhold
            if ($queue->musiconhold != null) {
                $this->fastagi->exec('Set', "CHANNEL(musicclass)={$queue->musiconhold}");
            }

            if ($queueActiveMemberCount == 0) {
                $this->fastagi->mylog("queueda registered extension yok.");
                return true;
            }

            $file = $queue->customer_id . '/' . date('Ymd') . '_' . date('His') . '_' . $queue->name;
            $this->fastagi->exec('Set', "CDR(monitor)={$file}");
            /*  $this->fastagi->exec("Set",
                  "MIXMONITOR_EXEC=\"avconv -i /var/spool/asterisk/monitor/{$file}.wav -f mp3 /var/spool/asterisk/monitor/{$file}.mp3 && rm /var/spool/asterisk/monitor/{$file}.wav\"");*/
            $this->fastagi->exec('MixMonitor',
                '/var/spool/asterisk/monitor/' . $file . '.wav,W(4),${MIXMONITOR_EXEC}');


            $this->fastagi->exec('Set', "CDR(route)=o2q");
            $this->fastagi->mylog("queue forward   Queue, {$queue->name},,,,{$queue->announce_frequency}");

            $this->fastagi->exec("Queue", "{$queue_name},,,,{$queue->timeout}");
            exit;
        } else {
            $this->fastagi->mylog("queue not found");
            $this->fastagi->hangup();

            return true;
        }


    }

    public function callConference($id)
    {

        $conference = CustomerConfbridge::find($id);
        if ($conference) {
            $confno = $conference->customer_id . "*" . $conference->confno;

            if ($conf_type = $this->checkPassword($conference->pin, $conference->adminpin)) {
                $this->fastagi->exec("Playback", "conf-join");
                if ($conf_type == 2) { // admin
                    $this->fastagi->exec("ConfBridge", "{$confno}, default_bridge,default_admin");
                } else {
                    $this->fastagi->exec("ConfBridge", "{$confno}, default_bridge,default_user");
                }
            } else {
                $this->fastagi->mylog(">sifre 3 kez yanlis girildi");
                $this->fastagi->hangup();

            }

        } else {
            $this->fastagi->mylog("conference not found");
            $this->fastagi->hangup();
        }

        return true;

    }


    public function getCallerIDFromContacts($customer, $phone)
    {

        $contact = CustomerContact::with('items')
            ->whereHas('items', function ($query) use ($phone) {
                $phone = substr($phone, -10);
                $query->whereIn('item', ['phone', 'gsm', 'fax']);
                $query->whereRaw("value LIKE '%$phone'");

            })
            ->where('customer_id', '=', $customer->id)
            ->first();
        $person = ['name' => $phone, 'phone' => $phone, 'vip' => 0];
        if (!$contact) {
            $customerContactRequest = AGIHelper::customerContactRequest($this->fastagi, $customer, $phone);
            if ($customerContactRequest) {
                $this->fastagi->mylog('Customer Contact Request Result: Name:' . $customerContactRequest['name']);
                return ['name' => $customerContactRequest['name'], 'phone' => $phone, 'vip' => $customerContactRequest['vip']];
            }
        }

        if ($contact) {
            $person = ['name' => $contact->name, 'phone' => $phone, 'vip' => $contact->vip];
        } else {
            return $person;
        }

        if ($contact->items[0]->label != null) {
            $person = ['name' => $contact->items[0]->label, 'phone' => $phone, 'vip' => $contact->vip];
        }

        $forward = $this->contactForward($contact->id);
        if ($forward) {

            $person['forward'] = $forward;
        }


        return $person;

    }

    public function getCustomerExtensionForward($customer_extension_id)
    {

        //todo :: müşterinin yerel saatine göre işlem yapması lazım.
        $today_day = date('N');
        $today_month = date('m');
        $today_time = date('H:i');

        $this->fastagi->mylog($today_time);
        $customer_forwards = CustomerForward::with([
            'schedule_template_hours' =>
                function ($query) use ($today_day, $today_month, $today_time) {
                    $query->whereIn('day', [$today_day, 0]);
                    $query->whereIn('month', [$today_month, 0]);
                    $query->where('start', '<=', $today_time);
                    $query->where('end', '>=', $today_time);

                }
        ])
            ->where('customer_extension_id', $customer_extension_id)->get();


        $forward = [];

        foreach ($customer_forwards as $customer_forward) {


            if ($customer_forward->customer_schedule_templates_id == null || count($customer_forward->schedule_template_hours) > 0) {
                $behaviors = explode(',', $customer_forward->forward_behavior);

                if (!is_array($behaviors)) {
                    $behaviors = [$customer_forward->forward_behavior];
                }
                foreach ($behaviors as $behavior) {
                    if (!isset($forward[$behavior])) {
                        $forward[$behavior] = [];
                    }
                    $numbers = explode(',', $customer_forward->forward_numbers);
                    if (!is_array($numbers)) {
                        $numbers = [$customer_forward->forward_numbers];
                    }
                    foreach ($numbers as $number) {
                        array_push($forward[$behavior], $number);

                    }
                }
                if ($customer_forward->ringing_duration != 0) {
                    $forward['ringing_duration'] = $customer_forward->ringing_duration;
                }

            }
        }
        $forward = array_reverse($forward, true);

        return count($forward) == 0 ? false : $forward;

    }


    public function contactForward($customer_contact_id)
    {

        $today_day = date('N');
        $today_month = date('m');
        $today_time = date('H:i');
        $default_forward = false;
        $customer_contact_forwards = CustomerContactForward::with([
            'schedule_template_hours' =>
                function ($query) use ($today_day, $today_month, $today_time) {
                    $query->whereIn('day', [$today_day, 0]);
                    $query->whereIn('month', [$today_month, 0]);
                    $query->where('start', '<=', $today_time);
                    $query->where('end', '>=', $today_time);

                }

        ])
            ->where('customer_contact_id', '=', $customer_contact_id)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($customer_contact_forwards as $customer_contact_forward) {


            if (count($customer_contact_forward->schedule_template_hours) > 0) {

                $forward = [
                    'type'     => $customer_contact_forward->forward,
                    'data'     => $customer_contact_forward->data,
                    'template' => $customer_contact_forward->customer_schedule_template_id
                ];
            } else {
                $default_forward = [
                    'type'     => $customer_contact_forward->forward,
                    'data'     => $customer_contact_forward->data,
                    'template' => 'Default'
                ];
            }

        }

        if (!isset($forward)) {
            $forward = $default_forward;
        }
        return isset($forward) ? $forward : false;

    }


    public function didForward($customer_did, $caller_number, $day_night_mode, $type = 'voice')
    {

        if ($day_night_mode == 'night') {
            $this->fastagi->mylog(" Gece Modu : Aktif");
            $customer_did_forward = CustomerDIDForward::where('customer_did_id', '=', $customer_did->id)
                ->where('customer_schedule_template_id', '-1')
                ->orderBy('id', 'desc')
                ->first();

            if ($customer_did_forward) {
                return [
                    'type'                          => $customer_did_forward->forward,
                    'data'                          => $customer_did_forward->data,
                    'template'                      => 'Night Mode',
                    'customer_schedule_template_id' => '-1'
                ];
            }
            return false;
        }
        $today_day = date('N');
        $today_month = date('m');
        $today_time = date('H:i');

        $customer_did_forwards = CustomerDIDForward::with([
            'schedule_template_hours' =>
                function ($query) use ($today_day, $today_month, $today_time) {
                    $query->whereIn('day', [$today_day, 0]);
                    $query->whereIn('month', [$today_month, 0]);
                    $query->where('start', '<=', $today_time);
                    $query->where('end', '>=', $today_time);

                }

        ])
            ->where('customer_did_id', '=', $customer_did->id)
            ->orderBy('id', 'desc')
            ->get();

        if (count($customer_did_forwards) > 0) {
            return $this->findDidForward($customer_did_forwards, $caller_number, $type);
        }

        $incoming_callplan_forwards = CustomerCallPlanIncoming::with([
            'schedule_template_hours' =>
                function ($query) use ($today_day, $today_month, $today_time) {
                    $query->whereIn('day', [$today_day, 0]);
                    $query->whereIn('month', [$today_month, 0]);
                    $query->where('start', '<=', $today_time);
                    $query->where('end', '>=', $today_time);

                }

        ])
            ->where('customer_id', '=', $customer_did->customer_id)
            ->orderBy('id', 'desc')
            ->get();
        if (count($incoming_callplan_forwards) > 0) {
            return $this->findDidForward($incoming_callplan_forwards, $caller_number, $type);
        }
        return false;
    }


    private function findDidForward($didForwards, $caller_number, $type)
    {
        $default_forward = false;
        foreach ($didForwards as $didForward) {


            if ($didForward->forward == 'extension') {
                $data = explode('-', $didForward->data);
                $didForward->data = $data[0];

                if (isset($data[1]) && $data[1] == 'fax') {
                    $didForward->forward = 'fax';
                }
            }

            if (count($didForward->schedule_template_hours) > 0) {


                switch ($didForward->callerid_rule) {
                    case 'none':
                        $forward = [
                            'rule'                          => 'No CallerId Rule',
                            'type'                          => $didForward->forward,
                            'data'                          => $didForward->data,
                            'template'                      => $didForward->customer_schedule_template_id,
                            'customer_schedule_template_id' => $didForward->customer_schedule_template_id,
                        ];
                        break;
                    case 'number-template':

                        $number_template = str_replace("X", '\d', $didForward->callerid_data);
                        if (preg_match("/^{$number_template}/", $caller_number)) {

                            $specialForward = [
                                'rule'                          => $didForward->callerid_rule . ' ' . $didForward->callerid_data,
                                'type'                          => $didForward->forward,
                                'data'                          => $didForward->data,
                                'template'                      => $didForward->customer_schedule_template_id,
                                'customer_schedule_template_id' => $didForward->customer_schedule_template_id,
                            ];

                        }
                        break;
                }

                if ($didForward->forward == 'fax' && $type == 'fax') {
                    return $forward;
                }
            } elseif ($didForward->customer_schedule_template_id == 0 || $didForward->customer_schedule_template_id == null) {


                $default_forward = [
                    'type'                          => $didForward->forward,
                    'data'                          => $didForward->data,
                    'template'                      => 'Default',
                    'customer_schedule_template_id' => '0'
                ];

                if ($didForward->forward == 'fax' && $type == 'fax') {
                    return $default_forward;
                }
            }


        }
        if (!isset($forward) && !isset($specialForward)) {
            $forward = $default_forward;
        }
        if (isset($specialForward)) {
            return $specialForward;
        }

        return isset($forward) ? $forward : false;

    }

    public function fixNumber($number, $params)
    {

        $this->fastagi->mylog([
            'Numara'    => $number,
            'Alan Kodu' => $params['area_code'],
            'Ulke'      => $params['did_country'],
        ]);
        if (substr($number, 0, 2) == "00") {
            return substr_replace($number, "", 0, 2);
        }
        $prefix = '';
        $number = str_replace("+", "", $number);
        switch ($params['did_country']) {

            case "tr":

                if (substr($number, 0, 2) == "00") {
                    $number = substr_replace($number, "", 0, 2);
                }
                $number = intval($number);
                $n = 12 - strlen($number);
                $prefix = substr('90', 0, $n);

                break;
            default:
                break;
        }
        return $prefix . $number;
    }

    // todo:: ami functionsa taşı
    public function completeNumber($number, $params)
    {

        // bu fonksiyonda yapılan değişiklikler app/Jobs/SendFax.php de de yapılmalı !!!

        $this->fastagi->mylog("         Numara Tamamlama");
        $this->fastagi->mylog([
            'Numara'    => $number,
            'Alan Kodu' => $params['area_code'],
            'Ulke'      => $params['did_country'],
        ]);


        //softphoneden gelen + 00 a cevriliyor
        if (substr($number, 0, 1) == "+") {
            $number = str_replace("+", "00", $number);
            $this->fastagi->mylog("+ li numara cevrildi {$number}");
        }
        if (strlen($number) == 5) {
            return $number;
        }

        switch ($params['did_country']) {

            case "tr":


                if (substr($number, 0, 4) == "0090") {
                    $number = substr_replace($number, "", 0, 2);
                }

                if (substr($number, 0, 2) == "90") {
                    return $number;
                }
                if (substr($number, 0, 2) == "00") {
                    return $number;
                }

                if (substr($number, 0, 3) == "444") {
                    return '90' . $number;
                }


                $number = intval($number);
                $n = 12 - strlen($number);
                $params['area_code'] = '90' . $params['area_code'];
                $prefix = substr($params['area_code'], 0, $n);
                if (($n > 2 && strlen($number) != 7) || strlen($number) > 11) {
                    $invalid = true;
                }
                break;
            case "nl":

                if (substr($number, 0, 4) == "0031") {
                    $number = substr_replace($number, "", 0, 2);
                }


                if (substr($number, 0, 2) == "31") {
                    return $number;
                }
                if (substr($number, 0, 2) == "00") {
                    return $number;
                }


                $number = intval($number);
                $n = 11 - strlen($number);
                $params['area_code'] = '31' . $params['area_code'];
                $prefix = substr($params['area_code'], 0, $n);
                break;

            case "de":

                if (substr($number, 0, 4) == "0049") {
                    $number = substr_replace($number, "", 0, 2);
                }

                if (substr($number, 0, 2) == "49") {
                    return $number;
                }


                $number = intval($number);
                $n = 12 - strlen($number);
                $params['area_code'] = '49' . $params['area_code'];
                $prefix = substr($params['area_code'], 0, $n);
                break;


            case "dz":

                if (substr($number, 0, 5) == "00213") {
                    $number = substr_replace($number, "", 0, 2);
                }
                if (substr($number, 0, 3) == "213") {
                    return $number;
                }


                $number = intval($number);
                $n = 11 - strlen($number);
                $params['area_code'] = '231' . $params['area_code'];
                $prefix = substr($params['area_code'], 0, $n);
                break;
            default :
                $prefix = "";
        }
        if (isset($invalid)) {
            $this->fastagi->mylog("hatali numara tuslandi. Numara: {$number}", true);
            $this->fastagi->exec("Playback", 'privacy-incorrect');
            $this->fastagi->exec("NoCDR", "");
            $this->fastagi->hangup();
            exit;
        }
        if (!$params['did_country']) {
            return $number;
        }

        return $prefix . $number;

    }


    public function findCallPattern($phone, $country, $area_code = null)
    {
//todo:: dosya yolu define tanımlanacak
        if (file_exists("/var/www/html/itsp/app/Console/Commands/Agi/Lib/call_pattern.conf")) {

            $call_patterns = parse_ini_file("/var/www/html/itsp/app/Console/Commands/Agi/Lib/call_pattern.conf",
                true);
        }
        if (!isset($call_patterns[$country])) {
            return false;
        }
        $country_code = $call_patterns[$country]['code'];

        $strlen = strlen($phone);

        if (substr($phone, 0, 2) != '00' && strlen($phone) > 5) {


            $phone = substr_replace($phone, "", 0, strlen($country_code)); //ulke kodu kaldırılıyor


            if (substr($phone, 0, strlen($area_code)) == $area_code) { // area code eşleşiyorsa kaldırılıyor
                $phone = substr_replace($phone, "", 0, strlen($area_code));
            } else {
                switch ($country) {

                    case 'tr':
                        $phone = '0' . $phone;
                        break;
                    case 'nl':
                        $phone = '0' . $phone;
                        break;
                }
            }

        }


        foreach ($call_patterns[$country] as $key => $call_pattern) {

            if (substr($call_pattern, 0, 1) == "/" && preg_match($call_pattern, $phone)) {
                $pattern = $key;
                break;
            }

        }
        if ($pattern == 'special') {
            $pattern = 'domestic';
        }

        return isset($pattern) ? $pattern : false;

    }


// Read Functions


    function checkPassword($valid_password1, $valid_password2 = null)
    {
        $this->fastagi->mylog("checkPassword");

        for ($i = 0; $i < 3; $i++) {

            $this->fastagi->mylog("dongu {$i}");
            $this->fastagi->exec("Read", "PASS,conf-getpin,4,,5,3");
            $enter_password = $this->fastagi->get_variable('PASS');
            $enter_password = $enter_password['data'];
            if ($valid_password1 == $enter_password) {

                return 1;
            }
            if ($valid_password2 != null && $valid_password2 == $enter_password) {
                return 2;
            }
            if (!is_numeric($valid_password1)) {
                $this->fastagi->mylog("sifresiz giris yapiliyor");
                return 1;
            }
            $this->fastagi->exec("Playback", "conf-invalidpin");

        }

        $this->fastagi->mylog("Tuslanan Numara: {$enter_password}");

        return false;
    }

    public function getCallerIDFromChannel($channel)
    {
        preg_match("/SIP\\/(.*)-/", $channel, $matches);
        if (isset($matches[1])) {
            return $matches[1];
        }
        preg_match("/Local\\/(.*)-/", $channel, $matches);
        if (isset($matches[1])) { //outgoung forward
            $this->fastagi->exec('Set', "CDR(data)={$this->fastagi->request['agi_rdnis']}");

            return $this->fastagi->request['agi_rdnis'] != 'unknown' ? $this->fastagi->request['agi_rdnis'] : $this->fastagi->request['agi_callerid'];
        }

        return 'GECERSIZ';
    }

    public function checkMonitor($callerUser, $calleeUser, $scope)
    {
        $monitor = false;

        if (is_object($calleeUser) && (in_array($scope, explode(',', $calleeUser->monitor)))) {
            $monitor = true;
        }
        if (in_array($scope, explode(',', $callerUser->monitor))) {
            $monitor = true;
        }
        $callee_number = is_object($calleeUser) ? $calleeUser->name : $calleeUser;
        if ($monitor) {
            $file = $callerUser->customer_id . '/' . date('Ymd') . '_' . date('His') . '_' . $callerUser->name . '_' . $callee_number;
            $this->fastagi->exec('Set', "CDR(monitor)={$file}");
            /*  $this->fastagi->exec("Set",
                  "MIXMONITOR_EXEC=\"avconv -i /var/spool/asterisk/monitor/{$file}.wav -f mp3 /var/spool/asterisk/monitor/{$file}.mp3 && rm /var/spool/asterisk/monitor/{$file}.wav\"");*/
            $this->fastagi->exec('MixMonitor',
                '/var/spool/asterisk/monitor/' . $file . '.wav,W(4),${MIXMONITOR_EXEC}');
        }

        return true;
    }


    public function checkCustomerExtensionDnd($customerExtension)
    {

        if ($customerExtension->dnd == 1) {
            $this->fastagi->mylog("{$customerExtension->name} Extension DND  Aktif", true);
            $this->fastagi->exec("Playback", "extensionbusy");
            exit;
        }

        return true;
    }

//FAX Functions
    public function createFaxInbox($customer_did, $title, $file, $src)
    {
        $this->fastagi->mylog("mylog >> createFaxInbox basladi ");

        $fax_inbox = FaxInbox::create([
            "filename"        => $file,
            "title"           => $title,
            "comment"         => "",
            "src"             => $src,
            "customer_did_id" => $customer_did->id,
            "customer_id"     => $customer_did->customer_id,
            "reseller_id"     => 0,
            "ondate"          => date('Y-m-d H:i:s')
        ]);
        $inbox_id = $fax_inbox->id;
        $this->fastagi->mylog("inbox id {$inbox_id}");

        $faxUsers = FaxUser::whereCustomerId($customer_did->customer_id)->wherePwrInbox(FaxUser::PWR_NO)->get()->pluck('id')->all();

        $fax_rights = FaxRight::where([
            'customer_did_id' => $customer_did->id,
            'receive'         => FaxRight::RECIEVE_ALLOW
        ])->whereIn('fax_users_id', $faxUsers)->get();

        foreach ($fax_rights as $fax_right) {
            FaxInboxUsers::create([
                'fax_inbox_id' => $inbox_id,
                'fax_users_id' => $fax_right->fax_users_id,
                "customer_id"  => $customer_did->customer_id,
                'status'       => 'new',
            ]);
        }

        $superFaxUsers = FaxUser::whereCustomerId($customer_did->customer_id)->wherePwrInbox(FaxUser::PWR_YES)->get();

        foreach ($superFaxUsers as $superFaxUser) {
            FaxInboxUsers::create([
                'fax_inbox_id' => $inbox_id,
                'fax_users_id' => $superFaxUser->id,
                "customer_id"  => $customer_did->customer_id,
                'status'       => 'new',
            ]);
        }


        return $inbox_id;
    }

    public function checkTransfer($type)
    {

        if ($type == 'call-in') {

            $did_number = $this->fastagi->request['agi_dnid'];
            $cdr_customer_id = $this->fastagi->get_variable('CDR(customer_id)');
            $calleeid = $cdr_customer_id['data'] . '*' . $this->fastagi->request['agi_extension'];
            $calleeUser = CustomerExtension::with('customer')->where('name', $calleeid)->first();
            if ($calleeUser) {
                $this->fastagi->mylog(json_encode($calleeUser));
                $this->fastagi->mylog(" Dahili arama Transfer bulundu  {$calleeid} ");
                $this->fastagi->exec('Set', "CDR(route)=o2e");
                $this->callOutgoingToExtension($did_number, $calleeUser);
            }

            return true;

        }
        if ($type = 'call-out') {

            $callee_number = $this->fastagi->request['agi_extension'];
            $customer_user = $this->fastagi->get_variable('CUSTOMERUSER');
            $callerUser = CustomerExtension::with('customer')->where('name', $customer_user['data'])->first();
            if ($callerUser) {
                $this->fastagi->mylog("Dis arama  Transfer bulundu.  {$customer_user['data']} ");
                $this->callExtensionToOutgoing($callee_number, $callerUser);
            }
            return true;

        }


        return false;

    }

    public function setCustomerInfo($customer)
    {

        $this->fastagi->exec_setlanguage($customer->pbx_lang);
        if ($customer->current_timezone != null) {
            date_default_timezone_set($customer->current_timezone); //todo : düzelt !!!!
        }
    }

    public function redirectVoicemail($customerExtension, $sayMessage = true)
    {
        $callee_Exten = $customerExtension->customer_id . "*" . $customerExtension->name;
        $checkVoicemail = CustomerVoicemail::where('customer_extension_id', $customerExtension->id)->where('context', 'default')->count();
        if ($checkVoicemail > 0) {


            $dialStatus = $this->fastagi->get_variable('DIALSTATUS');
            if ($dialStatus['data'] != "ANSWER") {

                $this->fastagi->mylog("Redirect Voicemail {$callee_Exten}. Dial Status: {$dialStatus['data']} Say Message:" . ($sayMessage ? 'yes' : 'no'));
                if ($sayMessage) {
                    if ($dialStatus['data'] == 'BUSY') {
                        $this->fastagi->exec("Playback", "vm-vm-isonphone");
                    }
                    $this->fastagi->exec("Playback", "vm-intro");
                }
                $this->fastagi->exec("Voicemail", "{$callee_Exten},s");
                $this->fastagi->hangup();
                exit;
            }

        } else {
            $this->fastagi->mylog("{$callee_Exten} icin Voicemail bulunamadi");
        }
    }


    public function callNeighborExtension($calleeUser, $callerUser)
    {

        if (!$calleeUser) {
            $this->fastagi->exec("Playback", "invalid");
            $this->fastagi->mylog("calleeUser bulunamadi", true);
            $this->fastagi->exec("NoCDR", "");
            $this->fastagi->hangup();
            exit;
        }

        $callerID = $callerUser->customer_id . '*' . $callerUser->name;
        $calleeNumber = $calleeUser->customer_id . '*' . $calleeUser->name;

        $this->fastagi->exec('Set', "CDR(customer_id)={$callerUser->customer_id}");
        $this->fastagi->exec('Set', "CDR(server_id)={$callerUser->regserver}");
        $this->fastagi->exec('Set', "CDR(reseller_id)={$callerUser->customer->reseller_id}");

        $this->fastagi->exec('Set', "CDR(src_customer_extension_id)={$callerUser->id}");
        $this->fastagi->exec('Set', "CDR(src_user_id)={$callerUser->web_user_id}");
        $this->fastagi->exec('Set', "CDR(dst_customer_extension_id)={$calleeUser->id}");
        $this->fastagi->exec('Set', "CDR(dst_user_id)={$calleeUser->web_user_id}");
        $this->fastagi->exec("Set", "CALLERID(num)={$callerUser->id}");
        $this->fastagi->exec('Set', "CDR(route)=e2n");


        $this->fastagi->exec("Dial", "SIP/internaltrunk/{$calleeNumber},30");
    }

    public function ipForward($ip, $callee_number, $caller_number, $callerid = null)
    {
        $this->fastagi->mylog('------- Ip Forward');
        $this->fastagi->exec('Set', "CDR(route)=if");
        if ($callerid != null) {
            $this->fastagi->exec("Set", "CALLERID(name)={$callerid}");
        }
        $this->fastagi->exec("Set", "CALLERID(num)={$caller_number}");
        $this->fastagi->exec("Dial", "SIP/{$ip}/{$callee_number}");
    }

    public function callCustomerCallPlan($callee_number, $callerUser)
    {
        $customerCallPlan = AGIHelper::getCustomerCallPlan($callee_number, $callerUser->customer_id);
        if (!$customerCallPlan) {
            $this->fastagi->mylog('CustomerCallPlan bulunamadi');
            return false;
        } else {


            $this->fastagi->exec('Set', "CDR(customer_id)={$callerUser->customer_id}");
            $this->fastagi->exec('Set', "CDR(server_id)={$callerUser->regserver}");
            $this->fastagi->exec('Set', "CDR(reseller_id)={$callerUser->customer->reseller_id}");
            $this->fastagi->exec('Set', "CDR(src_customer_extension_id)={$callerUser->id}");
            $this->fastagi->exec('Set', "CDR(src_user_id)={$callerUser->web_user_id}");

            $this->fastagi->mylog('CustomerCallPlan bulundu.');
            $this->fastagi->mylog(['Tip' => $customerCallPlan->action_type, 'Data' => $customerCallPlan->data]);

            $callee_number = AGIHelper::callPlanCalleeNumberFix($callee_number, $customerCallPlan);
            if ($customerCallPlan->action_type == 'change_did') {
                $this->callExtensionToOutgoing($callee_number, $callerUser, ['customer_did_id' => $customerCallPlan->data]);
            }
            if ($customerCallPlan->action_type == 'ip_forward') {

                $callerid = $callerUser->name;
                $this->ipForward($customerCallPlan->data, $callee_number, ($callerUser->customer_id . '*' . $callerUser->name), $callerid);
            }
            if ($customerCallPlan->action_type == 'default') {
                return $callee_number; // type default olduğunda düzeltilmiş caller_numberi gönderir
            }
            $this->fastagi->hangup();
            exit;

        }
    }


    public function test()
    {

        $this->fastagi->exec("Dial", "SIP/3*500,30,TtKk");
    }

}
