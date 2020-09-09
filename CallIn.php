<?php

namespace App\Console\Commands\Agi;

use App\Models\CDR;
use App\Models\Customer\CallCenter\Report\AgentSummary;
use App\Models\Customer\CustomerDID;
use Illuminate\Console\Command;
use App\Models\Customer\Customer;
use App\Models\Customer\CustomerSummary;
use Storage;
use App\Services\DynamicMail;
use App\Models\Customer\CustomerVoicemail;
use App\Models\Customer\CustomerVoicemailMessages;
use Symfony\Component\Process\Process;
use DB;
use Log;

use App\Console\Commands\Agi\Lib\PhpAgi;
use App\Console\Commands\Agi\Lib\AGIActions;
use App\Console\Commands\Agi\Lib\AGIHelper;
use App\Models\Customer\CustomerExtension;

trait CallIn
{

    public function callIn($agi)
    {

        $agiactions = new AGIActions($agi);

        $agi->exec('Set', "CDR(route)=e2e");


// caller user
        $agi->mylog(" channel : {$agi->request['agi_channel']}");
        $callerid = $agiactions->getCallerIDFromChannel($agi->request['agi_channel']);
        if ($agi->request['agi_context'] == 'incoming') {
            // $callerid = $agi->request['agi_extension'];
        }
        $agi->mylog(" callerid : {$callerid}");

        $callerUser = CustomerExtension::with('customer')->where('name', $callerid)->first();

        if (!$callerUser) {
            $callerUser = CustomerExtension::with('customer')->where('name', $agi->request['agi_calleridname'])->first();
        }
// check user is exists
        if (!$callerUser) {

            // Check Neighbor customer call
            $agi_extension = explode('*', $agi->request['agi_extension']);
            if (count($agi_extension) > 1 && $agi_extension[0] != $callerUser->customer_id) {
                $calleeUser = CustomerExtension::with('customer')->where('name', $agi->request['agi_extension'])->first();
                $agi->mylog(" Neighbor customer call.. ");
                $agi->exec('Set', "CDR(route)=e2n");
                $agiactions->callNeighborExtension($calleeUser, $callerUser);
                exit;
            }


            if ($agiactions->checkTransfer('call-in')) {
                exit;
            }

            $agi->exec("Playback", "invalid");
            $agi->mylog(" not found callerUser agi_callerid : {$callerid}");
            $agi->conlog(json_encode($agi->request['agi_callerid']));
            $agi->exec("NoCDR", "");
            $agi->hangup();
            exit;
        }

        //Set Customer Info
        $agiactions->setCustomerInfo($callerUser->customer);


        if ($callerUser->lastms <= 0) {
            $agi->exec("Playback", "disabled");
            $agi->mylog("callerUser is not registered! {$callerUser->callerid}  {$callerUser->id} lastms:{$callerUser->lastms}", true);
            $agi->exec("NoCDR", "");
            $agi->hangup();
            exit;
        }
        $agi->exec("Set", "CALLERID(name)={$callerUser->callerid}");
        $agi->exec("Set", "CALLERID(num)={$callerUser->name}");
        $agi->exec('Set', "CDR(customer_id)={$callerUser->customer_id}");

        // check call right
        $agi->mylog(" call rights: {$callerUser->call_rights} ");
        if (!AGIHelper::checkCallPermission($callerUser, 'extension') || (strlen($agi->request['agi_extension']) == 5 && !AGIHelper::checkCallPermission($callerUser, 'exclusive'))) {
            $agi->exec("Playback", "nodialpermission");
            $agi->mylog(" no dial permission for extension");
            $agi->exec("NoCDR", "");
            exit;
        }

        //Customer CallPlan varsa plana göre arar
        $dnid = $agiactions->callCustomerCallPlan($agi->request['agi_extension'], $callerUser);
        $dnid = AGIHelper::fixDNID($callerUser, $dnid ? $dnid : $agi->request['agi_extension']);

        // LOCATION TO LOCATION L2L CONTEXT
        if (strlen($agi->request['agi_extension']) == 6 && !strpos($agi->request['agi_extension'], "*")) {
            $agi->mylog("CONTEXT LOCATION TO LOCATION");
            $callee_number = $agi->request['agi_extension'];
            $area_prefix = substr($agi->request['agi_extension'], 0, 2);
            $agi->mylog("location prefix " . $area_prefix);

            if ($location_customer = Customer::where('location_prefix', $area_prefix)->first()) {
                $dnid = $location_customer->id . "*" . substr($agi->request['agi_extension'], 2);
                $agi->mylog("dnid " . $dnid);

                $agi->exec("Set", "CALLERID(num)=" . $callerUser->customer->location_prefix . $callerUser->name);
                $agi->exec('Set', "CDR(route)=l2l");
                $agi->exec('Set', "CDR(dst)={$agi->request['agi_extension']}");
                $agi->exec('Set', "CDR(data)={$agi->request['agi_extension']}");
            }
        }

        // LOCATION TO LOCATION L2L CONTEXT 8XXX
        if ($callerUser->customer_id != 1 && preg_match("/^(8)([0-9]){3,4}$/", $agi->request['agi_extension'])) {
            $agi->mylog("CONTEXT LOCATION TO LOCATION 8XXX MERKEZ");
            $callee_number = "11" . $agi->request['agi_extension'];

            if ($location_customer = Customer::find(1)) {
                $dnid = $location_customer->id . "*" . substr($callee_number, 2);
                $agi->mylog("dnid " . $dnid);

                $agi->exec("Set", "CALLERID(num)=" . $callerUser->customer->location_prefix . $callerUser->name);
                $agi->exec('Set', "CDR(route)=l2l");
                $agi->exec('Set', "CDR(dst)={$agi->request['agi_extension']}");
                $agi->exec('Set', "CDR(data)={$agi->request['agi_extension']}");
            }
        }

        if (isset($callee_number) && preg_match("/5001|5098|5483|5484|5583|5584|5585|5586/", substr($agi->request['agi_extension'], -4))) {

            if (strlen($agi->request['agi_extension']) == 4) {
                $callee_number = "11" . $agi->request['agi_extension'];
            }

            $customer_did = CustomerDID::with(['did', 'customer'])
                ->wherehas('did', function ($query) use ($callerUser) {
                    $query->where('did', 'like', '%0764' . $callerUser->customer->location_prefix);
                })
                ->where('status', '<>', 'passive')
                ->first();

            $params['customer_did_id'] = $customer_did->id;

            $agi->mylog("PARAM DID ID IS {$params['customer_did_id']}");

            $agiactions->callExtensionToOutgoing("0764" . $callee_number, $callerUser, $params);
        }

        $agiactions->callExtensionToExtension($dnid, $callerUser);

        return true;
    }
}
