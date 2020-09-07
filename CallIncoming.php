<?php

namespace App\Console\Commands\Agi;

use App\Console\Commands\Agi\Lib\AGIHelper;
use App\Models\Customer\Customer;
use \App\Models\Customer\CustomerExtension;
use \App\Models\Customer\CustomerDID;
use App\Models\Customer\CustomerIVR;
use App\Console\Commands\Agi\Lib\AGIActions;
use App\Models\Customer\CustomerBlacklist;
use App\Console\Commands\Agi\IVR;

trait CallIncoming
{


    public function callIncoming($agi)
    {


        $agiactions = new AGIActions($agi);

        // THIS FOR HUAWEI ROUTER FXS OUTGOING CALL
        if (preg_match("/^(64)([0-9]){6}$|^(0764)([0-9]){6}$/", $agi->request['agi_callerid'])) {
            $agi->mylog("FXS IN TO OUTGOING CALL START");

            if (preg_match("/^(64)([0-9]){6}$/", $agi->request['agi_callerid'])) {
//                $did_number = "07".substr($agi->request['agi_callerid'], 0, 4);
                $area_prefix = substr($agi->request['agi_callerid'], 2, 2);
                $agi->mylog("CALLER ID STARTS WITH 64");
            } else {
//                $did_number = substr($agi->request['agi_callerid'], 0, 6);
                $area_prefix = substr($agi->request['agi_callerid'], 4, 2);
                $agi->mylog("CALLER ID STARTS WITH 0764");
            }

//            $customer_did = CustomerDID::with(['did', 'customer'])
//                ->wherehas('did', function ($query) use ($did_number) {
//                    $query->where('did', 'like', '%' . $did_number);
//                })
//                ->where('status', '<>', 'passive')
//                ->first();

            $agi->mylog("location prefix " . $area_prefix);
            $location_customer = Customer::where('location_prefix', $area_prefix)->first();
            
            $callerUser = $location_customer->id . "*" . substr($agi->request['agi_callerid'], -4);
            $agi->mylog("CALLER USER STR IS {$callerUser}");
            $callerUser = CustomerExtension::where("name", $callerUser)->first();
            $agi->mylog("CALLER USER IS {$callerUser->name} , CALLEE NUMBER IS: {$agi->request['agi_dnid']}");

            $agiactions->callExtensionToOutgoing($agi->request['agi_dnid'], $callerUser);

        }


        $did_number = str_replace('+', '', $agi->request['agi_dnid']);

        if (!is_numeric($did_number)) {
            $header_to = $agi->get_variable('SIP_HEADER(TO)');


            preg_match("/sip:(.*)@/", $header_to['data'], $output_array);
            if (isset($output_array[1])) {
                $did_number = $output_array[1];
                $did_number = str_replace('+', '', $did_number);
                $agi->mylog(" HEADER TO : " . $header_to['data']);
                $agi->mylog(" HEADER TO DID Number : " . $did_number);
            } else {


                preg_match("/Local\/(.*)@/", $agi->request['agi_channel'], $output_array);
                if (isset($output_array[1])) {
                    $did_number = $output_array[1];
                    $did_number = str_replace('+', '', $did_number);
                    $agi->mylog(" CHANNEL TO DID Number : {$did_number}  Local DID Call");
                }


            }


        }


        $customer_did = CustomerDID::with(['did', 'customer'])
            ->wherehas('did', function ($query) use ($did_number) {
                $query->where('did', 'like', '%' . $did_number);
            })
            ->where('status', '<>', 'passive')
            ->get();
        if (count($customer_did) > 1) {
            // DID kullanımda değil
            $agi->exec("NoCDR", "");
            $agi->exec("Playback", "outofuse");
            $agi->mylog(" Birden fazla DID bulundu . {$did_number}  ");
            $agi->hangup();
            exit;
        }
        $customer_did = $customer_did->first();


        if (!$customer_did) {

            // TTVPN tto EXTENSION ttvpn2e CONTEXT
            if (preg_match("/^(0764)([0-9]){4}$|^(0764)([0-9]){6}$/", $agi->request['agi_extension'])) {

                if (preg_match("/^(0764)([0-9]){6}$/", $agi->request['agi_extension'])) {
                    $agi->mylog("INCOMING TTVPN MERKEZ 0764XXXXXX");
                    $area_prefix = substr($agi->request['agi_extension'], 4, 2);
                    $agi->mylog("location prefix " . $area_prefix);
                    $location_customer = Customer::where('location_prefix', $area_prefix)->first();
                } else {
                    $agi->mylog("INCOMING TTVPN MERKEZ 0764XXXX");
                    $location_customer = Customer::find(1);
                }

                if ($location_customer) {
                    $dnid = $location_customer->id . "*" . substr($agi->request['agi_extension'], -4);

                    $agi->mylog("dnid " . $dnid);
                    $callee_user = CustomerExtension::where("name", $dnid)->first();
//                    $agi->exec('Set', "CDR(dst)=" . substr($agi->request['agi_extension'], 0));
                    $agi->exec('Set', "CDR(src)={$agi->request['agi_callerid']}");
                    $agi->exec('Set', "CDR(data)={$agi->request['agi_extension']}");
                    $agi->exec_setlanguage($location_customer->pbx_lang);
                    $agi->exec('Set', "CDR(customer_id)=" . $location_customer->id);
                    $agi->exec('Set', "CDR(reseller_id)=" . $location_customer->reseller_id);

                    $callerUser = CustomerExtension::with('customer')->where('name', $location_customer->id . "*9999")->first();

                    if ($callerUser) {
                        $agi->mylog("CALLIN CALLER USER " . $location_customer->id);
                        $agi->mylog("SEARCH CALLPLAN FOR " . substr($agi->request['agi_extension'], -4));
                        $agiactions->callCustomerCallPlan(substr($agi->request['agi_extension'], -4), $callerUser);
                    } else {
                        $agi->mylog("CALLIN: NO CALLER USER " . $location_customer->id . "*9999");
                    }

                    if ($callee_user) {
                        $agiactions->callOutgoingToExtension($agi->request['agi_callerid'], $callee_user);
                        $agi->exec('Set', "CDR(route)=TTVPN2E");
                    }
                }
            }

            if (preg_match("/^(0764)([0-9]){6}$/", $agi->request['agi_extension']) && !strpos($agi->request['agi_extension'], "*")) {
                $agi->mylog("CONTEXT INCOMING 0764 TO LOCATION O2L");
                $did_number = substr($agi->request['agi_extension'], 0, 6);
                $customer_did = CustomerDID::with(['did', 'customer'])
                    ->wherehas('did', function ($query) use ($did_number) {
                        $query->where('did', 'like', '%' . $did_number);
                    })
                    ->where('status', '<>', 'passive')
                    ->first();
            }
        }


        if (!$customer_did) {

            // OUTGOING TO LOCATION O2L CONTEXT
//            if (strlen($agi->request['agi_extension']) == 6 && !strpos($agi->request['agi_extension'], "*")) {
//                $agi->mylog("CONTEXT OUTGOING TO LOCATION O2L");
//                $area_prefix = substr($agi->request['agi_extension'], 0, 2);
//                $agi->mylog("location prefix " . $area_prefix);
//
//                if ($location_customer = Customer::where('location_prefix', $area_prefix)->first()) {
//                    $dnid = $location_customer->id . "*" . substr($agi->request['agi_extension'], 2);
//                    $agi->mylog("dnid " . $dnid);
//                    $callee_user = CustomerExtension::where("name", $dnid)->first();
//                    $agi->exec('Set', "CDR(dst)=" . substr($agi->request['agi_extension'], 2));
//                    $agi->exec('Set', "CDR(data)={$agi->request['agi_extension']}");
//                    $agi->exec_setlanguage($location_customer->pbx_lang);
//                    $agi->exec('Set', "CDR(customer_id)=" . $location_customer->id);
//                    $agi->exec('Set', "CDR(reseller_id)=" . $location_customer->reseller_id);
//
//                    $callerUser = CustomerExtension::with('customer')->where('name', $location_customer->id . "*9999")->first();
//                    if ($callerUser) {
//                        $agi->mylog("CALLIN CALLER USER " . $location_customer->id);
//                        $agi->mylog("SEARCH CALLPLAN FOR " . substr($agi->request['agi_extension'], -4));
//                        $agiactions->callCustomerCallPlan(substr($agi->request['agi_extension'], -4), $callerUser);
//                    } else {
//                        $agi->mylog("CALLIN: NO CALLER USER " . $location_customer->id . "*9999");
//                    }
//
//                    $agiactions->callOutgoingToExtension($agi->request['agi_callerid'], $callee_user);
//                    $agi->exec('Set', "CDR(route)=o2l");
//                }
//
//                exit;
//            }


            // OUTGOING TO LOCATION J2EL CONTEXT
            if (preg_match("/^(091)/", $agi->request['agi_extension'])) {
                $agi->mylog("INCOMING JEMUS 91X.");
                $area_prefix = substr($agi->request['agi_extension'], 3, 2);
                $agi->mylog("location prefix " . $area_prefix);

                if ($location_customer = Customer::where('location_prefix', $area_prefix)->first()) {
                    $dnid = $location_customer->id . "*" . substr($agi->request['agi_extension'], 5);
                    $agi->mylog("dnid " . $dnid);
                    $callee_user = CustomerExtension::where("name", $dnid)->first();
                    $agi->exec('Set', "CDR(dst)=" . substr($agi->request['agi_extension'], 0));
                    $agi->exec('Set', "CDR(data)={$agi->request['agi_extension']}");
                    $agi->exec_setlanguage($location_customer->pbx_lang);
                    $agi->exec('Set', "CDR(customer_id)=" . $location_customer->id);
                    $agi->exec('Set', "CDR(reseller_id)=" . $location_customer->reseller_id);
                    $agiactions->callOutgoingToExtension($agi->request['agi_callerid'], $callee_user);
                    $agi->exec('Set', "CDR(route)=J2LE");
                }

                exit;
            }

            if (preg_match("/^(092)|^(093)|^(094)|^(095)/", $did_number)) {
                $agi->mylog("CONTEXT INCOMING JEMUS DID FWD");
                $did_number = substr($did_number, 0, 3);
                $customer_did = CustomerDID::with(['did', 'customer'])
                    ->wherehas('did', function ($query) use ($did_number) {
                        $query->where('did', 'like', '%' . $did_number);
                    })
                    ->where('status', '<>', 'passive')
                    ->first();

                if ($customer_did) {
                    $customer_id = $customer_did->customer_id;
                    $callerUser = CustomerExtension::with('customer')->where('name', $customer_did->customer_id . "*9999")->first();

                    if ($callerUser) {
                        $agi->mylog("CALLIN JEMUS DID FWD: CHECK CALLPLAN " . $customer_id);
                        $agiactions->callCustomerCallPlan($agi->request['agi_extension'], $callerUser);
                    } else {
                        $agi->mylog("CALLIN: NO CALLER USER " . $customer_id . "*9999");
                    }
                }
            }

            $callerid = str_replace('+', '', $agi->request['agi_callerid']);
            //Dahili aramasi varmı kontrol ediliyor
            $agiactions->callTrunkToExtension($callerid, $did_number);

            // DID kullanımda değil
            $agi->exec("NoCDR", "");
            $agi->exec("Playback", "outofuse");
            $agi->mylog(" did not in use ");
            $agi->hangup();
            exit;
        }
        //Set Customer Info
        $agiactions->setCustomerInfo($customer_did->customer);


        $agi->mylog("incoming type : " . $customer_did->incoming_type);


        if ($customer_did->incoming_type == 'fax') {
            $agi->mylog(" Forward Fax");
            $agi->exec('Wait', '2');
            $agi->exec('Goto', 'fax,1');
            exit;
        } elseif ($customer_did->incoming_type == 'both') {
            $agi->mylog(" Wait for Fax detection ");
            $agi->exec('Wait', '5');

            // Eger fax 'a redirect varsa EXTEN bos geliyor
            $exten = $agi->get_variable("EXTEN");

            if (!$exten['data']) {
                $agi->mylog(" Fax detected!!!");
                exit;
            }


        }
        if (AGIHelper::checkCallLimit($agi, $customer_did->customer, 'incoming') == false) {
            return true;
        }
        $agi->exec_setlanguage($customer_did->customer->pbx_lang);
        $agi->exec('Set', "CDR(customer_id)=" . $customer_did->customer->id);
        $agi->exec('Set', "CDR(reseller_id)=" . $customer_did->customer->reseller_id);
        $agi->exec('Set', "CDR(customer_did_id)=" . $customer_did->id);

        //Caller ID belirleniyor
        $callerid = str_replace('+', '', $agi->request['agi_callerid']);
        if (!is_numeric($callerid) && $customer_did->customer->pbx['secret_call'] == 'block') {

            $agi->exec("NoCDR", "");
            $agi->exec("Playback", "outofuse"); //todo :: sesi değiştir !!!!!!!!
            $agi->mylog("Gizli numara aranmalarina izin verilmiyor Customer SecretCall : {$customer_did->customer->pbx['secret_call']}", true);
            $agi->hangup();
            exit;
        }

        $agi->mylog(" agi_callerid:  {$callerid} , customer id : {$customer_did->customer_id}");


        $callerid = $agiactions->getCallerIDFromContacts($customer_did->customer, $callerid);


        $agi->mylog("           Arayan Kisi Bilgisi");
        $agi->mylog($callerid);

        $callerIDName = $callerid['name'];
        if ($customer_did->incoming_callerid != null) {
            $callerIDName = $customer_did->incoming_callerid . '|' . $callerid['name'];
        }
        $agi->exec("Set", "CALLERID(name)={$callerIDName}");
        $agi->exec("Set", "CALLERID(num)={$callerid['phone']}");


        $blacklist = CustomerBlacklist::where('customer_id', $customer_did->customer_id)->where('number', 'like', "%{$callerid['phone']}")->first();

        if ($blacklist) {
            $agi->mylog("Numara blacklistte  Numara.: {$callerid['phone']} ", true);
            $this->fastagi->hangup();
            exit;
        }

        $callerid['phone'] = $agiactions->fixNumber($callerid['phone'], [
            'did_country' => $customer_did->did->country,
            'area_code'   => $customer_did->area_code
        ]);
// DID yonlendirmesi alınıyor
        if (isset($callerid['forward']) && $customer_did->customer->pbx['day_night_mode'] != 'night') {
            $forward = $callerid['forward'];
            $agi->mylog(" {$callerid['name']} ({$callerid['phone']}) ozel tanımlanmis yonlendirme bulundu  ");
        } else {
            $forward = $agiactions->didForward($customer_did, $callerid['phone'], $customer_did->customer->pbx['day_night_mode']);
        }


        if ($forward) {
            $agi->mylog([
                'Yonlendirme Tipi' => $forward['type'],
                'Template'         => $forward['template'],
                'Details'          => $forward
            ]);
            $agi->exec("Set", "CDR(schedule_template_id)={$forward['customer_schedule_template_id']}");

            $forward_data = $forward['data'];
            switch ($forward['type']) {

                case "extension":

                    $calleeUser = CustomerExtension::with('customer')->find($forward_data);
                    $agi->mylog("  DID Forward  Sip Forward Data: {$forward_data} ");
                    if (!$calleeUser) {
                        $agi->mylog("Dahili telefon bulunamadi", true);
                        return true;
                    }
                    if (isset($callerid['phone'])) {
                        $agiactions->callOutgoingToExtension($callerid['phone'], $calleeUser);
                    } else {
                        $agi->mylog("Arayan bulunamadi", true);
                        $agi->mylog($callerid);
                    }
                    break;
                case "outgoing":
                    $agi->exec('Set', "CDR(route)=o2o");
                    $agi->mylog("DID Forward  Outgoing  Forward Data: {$forward_data}");
                    $agiactions->callOutgoing($customer_did, $forward_data, ['outgoing-forward' => true]);
                    break;
                case "queue":
                    $agi->exec('Set', "CDR(route)=o2q");

                    $agi->mylog(" DID Forward  queue Forward Data:{$forward_data}");
                    $agiactions->callQueue($forward_data);
                    exit;
                    break;
                case "conference":
                    $agi->exec('Set', "CDR(route)=o2c");
                    $agi->mylog(" DID Forward  conference Forward Data:{$forward_data}");

                    $agiactions->callConference($forward_data);

                    break;

                case "ivr":
                    $agi->mylog("DID Forward  IVR  {$forward_data}");
                    $agi->exec('Set', "CDR(route)=o2i");
                    $agi->mylog(" DID Forward  ivr Forward Data:{$forward_data}");
                    $customerIVR = CustomerIVR::select([
                        'customer_ivr.id as id',
                        'customer_ivr.name as name',
                        'customer_ivr.data as data',
                        'customer_ivr.lang as lang',
                        'customer_ivr.customer_id as customer_id'
                    ])->where('customer_ivr.id', $forward_data)->first();

                    if ($customerIVR) {

                        $_ivr = new IVR($agi, $customerIVR, $customer_did);
                        $_ivr->start();
                    } else {
                        $agi->mylog(" ivr not found", true);

                    }

                    break;
                case "ip-forward":
                    $agi->mylog("DID Forward  Ip Forward  {$forward_data}");
                    $agiactions->ipForward($forward_data, $customer_did->did->did, $callerid['phone']);
                    break;


            }


        } else {

            $agi->mylog("  DID   Yonlendirmesi  bulunamadi", true);
            $agi->exec("Playback", "outofuse");
            $agi->exec("NoCDR", "");
            $agi->hangup();
            exit;
        }


    }

}
