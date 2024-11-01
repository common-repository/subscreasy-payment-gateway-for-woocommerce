<?php

class PurchaseResponse {

    private $iso_codes = array(
        '00' => 'Approved or completed successfully',
        '01' => 'Refer to card issuer',
        '02' => 'Refer to card issuer’s special conditions',
        '03' => 'Invalid merchant',
        '04' => 'Pick-up card',
        '05' => 'Do not honor',
        '06' => 'Error',
        '07' => 'Pick-up card',
        '08' => 'Honor with identification',
        '09' => 'Request in progress',
        '11' => 'Approved (VIP)',
        '12' => 'Invalid transaction',
        '13' => 'Invalid amount',
        '14' => 'Invalid card number (no such number)',
        '15' => 'No such issuer',
        '30' => 'Format error',
        '31' => 'Bank not supported by switch',
        '33' => 'Expired card',
        '34' => 'Suspected fraud',
        '35' => 'Card acceptor contact acquirer',
        '36' => 'Restricted card',
        '37' => 'Card acceptor call acquirer security',
        '38' => 'Allowable PIN tries exceeded',
        '39' => 'No credit account',
        '41' => 'Lost card',
        '43' => 'Stolen card',
        '51' => 'Not sufficient funds',
        '54' => 'Expired card',
        '55' => 'Incorrect personal identification number',
        '56' => 'No card record',
        '57' => 'Transaction not permitted to cardholder',
        '58' => 'Transaction not permitted to terminal',
        '61' => 'Exceeds withdrawal amount limit',
        '62' => 'Restricted card',
        '65' => 'Exceeds withdrawal frequency limit',
        '68' => 'Response received too late / Timeout',
        '75' => 'Allowable number of PIN tries exceeded',
        '76' => 'Reserved for private use',
        '77' => 'Reserved for private use',
        '78' => 'Reserved for private use',
        '79' => 'Reserved for private use',
        '80' => 'Reserved for private use',
        '81' => 'Reserved for private use',
        '82' => 'Reserved for private use / No security module',
        '83' => 'Reserved for private use / No accounts',
        '84' => 'Reserved for private use',
        '85' => 'Reserved for private use',
        '86' => 'Reserved for private use',
        '87' => 'Reserved for private use / Bad track data',
        '88' => 'Reserved for private use',
        '89' => 'Reserved for private use',
        '90' => 'Cutoff is in process (switch ending a day’s business and starting the next. Transaction can be sent again in a few minutes) / Unable to authorize',
        '91' => 'Issuer or switch is inoperative / Unable to authorize',
        '92' => 'Financial institution or intermediate network facility cannot be found for routing / Decline',
        '94' => 'Duplicate transmission',
        '96' => 'System malfunction',
        'N0' => 'Reserved for private use / Unable to authorize',
        'N1' => 'Reserved for private use / Invalid PAN length',
        'N2' => 'Reserved for private use / Preauthorization full',
        'N3' => 'Reserved for private use / Maximum online refund reached',
        'N4' => 'Reserved for private use / Maximum offline refund reached',
        'N5' => 'Reserved for private use / Maximum credit per refund',
        'N6' => 'Reserved for private use / Maximum refund credit reached',
        'N7' => 'Reserved for private use / Customer selected negative file reason',
        'N8' => 'Reserved for private use / Over floor limit',
        'N9' => 'Reserved for private use / Maximum number refund credits',
        'O0' => 'Reserved for private use / Referral file full',
        'O1' => 'Reserved for private use / NEG file problem',
        'O2' => 'Reserved for private use / Advance less than minimum',
        'O3' => 'Reserved for private use / Delinquent',
        'O4' => 'Reserved for private use / Over limit table',
        'O5' => 'Reserved for private use / PIN required',
        'O6' => 'Reserved for private use / Mod 10 check',
        'O7' => 'Reserved for private use',
        'O8' => 'Reserved for private use',
        'O9' => 'Reserved for private use',
        'P0' => 'Reserved for private use',
        'P1' => 'Reserved for private use / Over daily limit',
        'P2' => 'Reserved for private use',
        'P3' => 'Reserved for private use / Advance less than minimum',
        'P4' => 'Reserved for private use / Number of times used',
        'P5' => 'Reserved for private use / Delinquent',
        'P6' => 'Reserved for private use / Over limit table',
        'P7' => 'Reserved for private use / Advance less than minimum',
        'P8' => 'Reserved for private use / Administrative card needed',
        'P9' => 'Reserved for private use / Enter lesser amount',
        'Q0' => 'Reserved for private use / Invalid transaction date',
        'Q1' => 'Reserved for private use / Invalid expiration date',
        'Q2' => 'Reserved for private use / Invalid transaction code',
        'Q3' => 'Reserved for private use / Advance less than minimum',
        'Q4' => 'Reserved for private use / Number of times used',
        'Q5' => 'Reserved for private use / Delinquent',
        'Q6' => 'Reserved for private use / Over limit table',
        'Q7' => 'Reserved for private use / Amount over maximum',
        'Q8' => 'Reserved for private use / Administrative card not found',
        'Q9' => 'Reserved for private use / Administrative card not allowed',
        'R0' => 'Reserved for private use',
        'R1' => 'Reserved for private use',
        'R2' => 'Reserved for private use',
        'R3' => 'Reserved for private use',
        'R4' => 'Reserved for private use',
        'R5' => 'Reserved for private use',
        'R6' => 'Reserved for private use',
        'R7' => 'Reserved for private use',
        'R8' => 'Reserved for private use / Card on national negative file',
        'S4' => 'PTLF full',
        'S5' => 'Reserved for private use',
        'S6' => 'Reserved for private use',
        'S7' => 'Reserved for private use',
        'S8' => 'Reserved for private use',
        'S9' => 'Reserved for private use / Unable to validate PIN; security module is down',
        'T1' => 'Reserved for private use / Invalid credit card advance amount',
        'T2' => 'Reserved for private use / Invalid transaction date',
        'T3' => 'Reserved for private use / Card not supported',
        'T4' => 'Reserved for private use / Amount over maximum',
        'T5' => 'Reserved for private use',
        'T6' => 'Reserved for private use',
        'T7' => 'Reserved for private use / Cash back exceeds daily limit',
        'T8' => 'Reserved for private use / Invalid account',
        'U0' => 'ARQC Failure Decline',
        'U1' => 'Security Module Parameter Error',
        'U2' => 'Security Module Failure',
        'U3' => 'KEYI Record Not Found',
        'U4' => 'ATC Check Failure',
        'U5' => 'CVR Decline',
        'U6' => 'TVR Decline',
        'U7' => 'Reason Online Code Decline',
        'U8' => 'Fallback Decline',
        'V0' => 'ARQC Failure Referral',
        'V1' => 'CVR Referral',
        'V2' => 'TVR Referral',
        'V3' => 'Reason Online Code Referral',
        'V4' => 'Fallback Referral',
        'V7' => 'ARQC Failure Capture',
        'V8' => 'CVR Capture',
        'V9' => 'TVR Capture'
    );

    private $post_params = null;
    private $get_params = null;
    private $is_authorized = false;
    private $is_transaction_error = false;
    private $rejected = false;
    private $blacklisted = false;
    private $service_unavailable = false;
    
    function is_authorized() {
        return $this->is_authorized;
    }

    function is_transaction_error() {
        return $this->is_transaction_error;
    }

    function __construct() {        
        $param_names = array(
            'paymentid',
            'result', 'auth', 'ref', 'tranid', 'postdate', 'trackid', 'responsecode', 'cvv2response', // Transaction ok.
            'errorCode', 'errorText',                                                                       // Transaction error.
            "chargingStatus", "paymentId", "authCode", "errorCode", "errorText", "subscriberId", "subscriberSecureId", "cardId"
        );

        $this->post_params = array();
        $default_value = array("options" => array("default" => null));
        
        foreach ($param_names as $name) {
            $val = filter_var(filter_input(INPUT_POST, $name, FILTER_DEFAULT, $default_value), FILTER_SANITIZE_STRING);
            $this->post_params[$name] = $val;
            // $this->log("$name = $val");
        }

        $this->get_params = array();
        foreach ($param_names as $name) {
            $val = filter_var(filter_input(INPUT_GET, $name, FILTER_DEFAULT, $default_value), FILTER_SANITIZE_STRING);
            $this->get_params[$name] = $val;
            // $this->log("$name = $val");
        }

        if ($this->get_params['paymentId']) {
            $this->is_authorized =     $this->get_params['chargingStatus'] === 'PAID';
            
            $this->rejected = $this->post_params['responsecode'] && $this->post_params['responsecode'] !== '00';
            $this->blacklisted = $this->post_params['result'] && $this->post_params['result'] === 'DENIED+BY+RISK';
            $this->service_unavailable = $this->post_params['result'] && $this->post_params['result'] === 'HOST+TIMEOUT';
            $this->card_error = $this->post_params['Error'] && $this->post_params['ErrorText'];
                        
            $this->is_transaction_error =    $this->rejected 
                                          || $this->blacklisted
                                          || $this->service_unavailable
                                          || $this->card_error;
        }
    }

    public function is_valid() {
        // captured/card error are both valid responses.
        return $this->is_authorized || $this->is_transaction_error;
    }

    public function get_error_message() {
        if ($this->is_authorized) {
            return null;
        }
        
        $message = 'Unknown error.';

        if ($this->rejected || $this->blacklisted || $this->service_unavailable) {
            $message = $this->post_params['result'];
            $response_code = $this->post_params['responsecode'];
            if (isset($this->iso_codes[$response_code])) {
                $message = sprintf(' %s (ISO code %s)', $this->iso_codes[$response_code], $response_code);
            }            
        }
        if ($this->card_error) {
            $message = $this->params['Error'] . ': ' . $this->params['ErrorText'];
        }
        return urldecode($message);
    }

    public function get_sanitized_request_params() {
        return $this->post_params;
    }

    public function get_param($name) {
        $result = null;
        if (isset($this->get_params[$name])) {
            $result = $this->get_params[$name];
        }
        return $result;
    }

    public function post_param($name) {
        $result = null;
        if (isset($this->post_params[$name])) {
            $result = $this->post_params[$name];
        }
        return $result;
    }
}
