<?php
/**
 * Created by PhpStorm.
 * User: eerdogan
 * Date: 15/09/15
 * Time: 15:17
 */
use App\Models\DIDGroup;
use App\Models\Reseller;
use App\Models\Server;
use App\Models\Access\User\User;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\SmsProvider;

Form::macro('select2', function ($name = 'inputName', $data, $default = null, array $attributes) {
    return Form::select($name, $data, $default, $attributes);
});
Form::macro('select_extension', function ($name = 'inputName', $default = null, array $attributes) {
    $data = [];

    return Form::select($name, $data, $default, $attributes);
});


Form::macro('customer_did_select', function ($name = 'customer_did_id', $default = null, array $attributes) {



    $customerDids = \App\Models\Customer\CustomerDID::with('did')->where('status','active')->get();
    $dids = [];
    foreach ($customerDids as $customerDid) {
        $dids[$customerDid->id] = $customerDid->did->did;

    }

    return Form::select($name, $dids, $default, $attributes);
});
Form::macro('did_group_select', function ($name = 'did_group', $default = null, array $attributes) {
    $did_groups = DIDGroup::lists('name', 'id');


    return Form::select($name, $did_groups, $default, $attributes);
});
Form::macro('rate_group_select', function ($name = 'rate_group', $default = null, array $attributes) {
    $did_groups = \App\Models\RateGroup::lists('name', 'id');


    return Form::select($name, $did_groups, $default, $attributes);
});
Form::macro('sms_provider_select', function ($name = 'provider', $default = null, $attributes = []) {

    $providers = SmsProvider::$smsProviders;

    return Form::select($name, $providers, $default, $attributes);
});

Form::macro('provider_select', function ($name = 'provider_id', $default = null, $attributes = []) {

//    $providers = ['' => 'Select Provider'];
//    $providers = $providers + Provider::lists('name', 'id')->all();
    $providers = Provider::lists('name', 'id')->all();

    return Form::select($name, $providers, $default, $attributes);
});

Form::macro('reseller_select', function ($name = 'reseller_id', $default = null, array $attributes) {
    $resellers = Reseller::select(['id', 'name'])->lists('name', 'id');

    return Form::select($name, $resellers, $default, $attributes);
});


Form::macro('server_select', function ($name = 'server_id', $default = null, array $attributes) {
    $servers = Server::lists('name', 'id');

    return Form::select($name, $servers, $default, $attributes);
});

Form::macro('selectRequired', function (
    $name,
    $options = [],
    $selected = null,
    $attributes = ['class' => 'form-control select2'],
    $disabled = []
) {

    $html = '<select name="' . $name . '"';
    foreach ($attributes as $attribute => $value) {
        $html .= ' ' . $attribute . '="' . $value . '"';
    }
    $html .= '>';
    if (isset($attributes['placeholder'])) {
        $html .= '<option value="" selected="selected">' . $attributes['placeholder'] . '</option>';
    }
    foreach ($options as $value => $text) {

        $html .= '<option value="' . $value . '" ';
        if (!is_null($selected) && $value == $selected) {
            $html .= 'selected="selected"';
        }
        $html .= (in_array($value, $disabled) || in_array($text, $disabled) ? ' disabled="disabled"' : '') . '>' .
            $text . (in_array($text, $disabled) || in_array($text,
                $disabled) ? ' ' . _(' / Busy or Reserved, Choose another one.') : '') . '</option>';
    }

    $html .= '</select>';

    return $html;
}
);

Form::macro('trunk_type_select', function ($name = 'trunk_type', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'trunk', 'key' => 'trunk_type'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }
    $values = [
        "friend" => "friend",
        "peer" => "peer"
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('qualify_select', function ($name = 'qualify', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'trunk', 'key' => 'qualify'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }
    $values = [
        "yes" => "yes",
        "no" => "no"
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('canreinvite_select', function ($name = 'canreinvite', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'trunk', 'key' => 'canreinvite'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }
    $values = [
        "no" => "no",
        "yes" => "yes",
        'nonat' => 'nonat',
        'update' => 'update',
        'update,nonat' => 'update,nonat'
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('dtmfmode_select', function ($name = 'dtmfmode', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'trunk', 'key' => 'dtmfmode'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }
    $values = [
        "rfc2833" => "rfc2833",
        "inband" => "inband",
        "auto" => "auto"
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('insecure_select', function ($name = 'insecure', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'trunk', 'key' => 'insecure'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }
    $values = [
        "port" => "port",
        "invite" => "invite",
        "port,invite" => "port,invite"
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('nat_select', function ($name = 'nat', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'trunk', 'key' => 'nat'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }

    $values = [
        "force_rport" => "force_rport",
        "comedia" => "comedia",
        "force_rport,comedia" => "force_rport,comedia"
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('smtp_ssl_select', function ($name = 'ssl', $default = null, array $attributes) {
    if ($default == null) {

        $default = Setting::where(['group' => 'smtp', 'key' => 'ssl'])->select('value')->first();
        $default = isset($default->value) ? $default->value : null;
    }
    $values = [
        "" => "NONE",
        "ssl" => "SSL",
        "tls" => "TLS",
        "starttls" => "STARTTLS",
        "ssl/tls" => "SSL/TLS",
    ];

    return Form::select($name, $values, $default, $attributes);
});

Form::macro('ticket_categories_select', function ($name = 'ticket_category', $default = null, array $attributes) {
    if ($default == null) {

//        $default = Setting::where(['group' => 'smtp', 'key' => 'ssl'])->select('value')->first();
//        $default = isset($default->value) ? $default->value : null;
    }
    foreach (\App\Models\Ticket::$categoryMap as $key => $value) {
        $values[$key] = _($value);
    }

    return Form::select($name, $values, $default, $attributes);
});


Form::macro('reseller_select_ajax', function ($name = 'reseller_id', $default = null, array $attributes) {

    $select = '<select role="select2ajax"';
    foreach ($attributes as $key => $value) {
        $select .= $key . '="' . $value . '" ';
    }
    $select .= '><option></option></select>';

    return $select;

});

Form::macro('customer_select_ajax', function ($name = 'customer_id', $default = null, array $attributes) {

    // reseller'a göre değişim isteniyorsa

    $select = '<select role="select2ajax"';
    foreach ($attributes as $key => $value) {
        $select .= $key . '="' . $value . '" ';
    }
    $select .= '><option></option></select>';

    return $select;

});

Form::macro('select2ajax', function ($name = null, $default = null, array $attributes) {

    // reseller'a göre değişim isteniyorsa

    $select = '<select role="select2ajax" name="' . $name . '" data-value="' . $default . '"';
    foreach ($attributes as $key => $value) {
        $select .= $key . '="' . $value . '" ';
    }
    $select .= '>';
//    if(!is_null($default)){
//        $select .= '<option selected>'..'</option>';
//    }
    $select .= '<option></option></select>';

    return $select;

});


Form::macro('select2Timezone', function ($name = 'timezones', $data, $default = 'Europe/Istanbul', array $attributes) {

    $timezones = getTimeZoneList();
    $zones_array = [];
    $timestamp = time();
    foreach (timezone_identifiers_list() as $key => $zone) {
        date_default_timezone_set($zone);
        $zones_array[$zone] = $zone . ' (' . date('P', $timestamp) . ')';
    }
    date_default_timezone_set('UTC');
    return Form::select($name, $zones_array, $default, $attributes);
});

Form::macro('select2Country', function ($name = 'country', $data, $default = 'tr', array $attributes) {

    $list = getCountryList();

    return Form::select($name, $list, $default, $attributes);
});

Form::macro('select2Language', function ($name = 'languages', $default = 'tr', array $attributes = [], $for = 'pbx') {

    if ($for == 'pbx') {
        $list = [
            'tr' => _('Turkish'),
            'en' => _('English'),
            'nl' => _('Dutch'),
            'de' => _('German'),
            'ru' => _('Russian'),
        ];
    } else {
        $list = [

            'tr_TR' => _('Türkçe'),
            'en_US' => _('English'),
        ];
    }


    return Form::select($name, $list, $default, $attributes);
});


Form::macro('selectMobileTransport', function ($name = 'mobile_transport', $default = 'tcp', array $attributes) {

    $modes = [
        'tcp' => _('TCP'),
        'udp' => _('UDP')
    ];

    return Form::select($name, $modes, $default, $attributes);
});
Form::macro('day_night_mode_select', function ($name = 'day_night_mode', $default = 'tr', array $attributes) {

    $modes = [
        'day' => _('Day'),
        'night' => _('Night')
    ];

    return Form::select($name, $modes, $default, $attributes);
});

Form::macro('secret_call_select', function ($name = 'secrect_call', $default = 'tr', array $attributes) {

    $modes = [
        'allow' => _('Allow'),
        'block' => _('Block')
    ];

    return Form::select($name, $modes, $default, $attributes);
});
Form::macro('balance_notification', function ($name = 'balance_notification', $default = '1', array $attributes) {

    $modes = [
        '1' => _('Active'),
        '0' => _('Passive')
    ];

    return Form::select($name, $modes, $default, $attributes);
});


Form::macro('payment_type_select', function ($name = 'payment_type', $default = 'pre_paid', array $attributes, $reseller_type = null) {

    $types = [
        '' => _('Payment Type'),
        'pre_paid' => _('Pre Paid'),
        'post_paid' => _('Post Paid')
    ];

    if ($reseller_type && $reseller_type->post_paid_customer == 0) {
        unset($types['post_paid']);
    }

    return Form::select($name, $types, $default, $attributes);
});

Form::macro('country_select', function ($name = 'lang', $default = 'tr', array $attributes) {

    $countries = [
        'tr' => _('Turkey'),
        'uk' => _('United Kingdom'),
        'nl' => _('Netherland'),
        'de' => _('Germany'),
    ];

    return Form::select($name, $countries, $default, $attributes);
});

Form::macro('currency_type_select', function ($name = 'currency', $default = '₺', array $attributes) {

    $types = [
        '₺' => _('₺'),
        '$' => _('$'),
        '€' => _('€')
    ];

    return Form::select($name, $types, $default, $attributes);
});

Form::macro('fax_right_select', function ($name = 'fax_right', $default = '0', array $attributes) {

    $types = [
        '0' => _('No'),
        '1' => _('Yes'),

    ];

    return Form::select($name, $types, $default, $attributes);
});
Form::macro('payment_total_select', function ($name = 'payment_total', $default = '0', array $attributes) {

    $types = [
        '5' => _('5'),
        '10' => _('10'),
        '15' => _('15'),
        '20' => _('20'),
        '25' => _('25'),
        '30' => _('30'),
        '40' => _('40'),
        '60' => _('60'),
        '70' => _('70'),
        '80' => _('80'),
        '90' => _('90'),
        '100' => _('100'),
    ];

    return Form::select($name, $types, $default, $attributes);
});
