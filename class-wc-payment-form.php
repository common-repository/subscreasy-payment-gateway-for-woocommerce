<?php

class PaymentForm {
    private $post_params = null;
    private $get_params = null;

    function __construct() {        
        $param_names = array(
            "subscreasy_ccNo", "subscreasy_expMonth", "subscreasy_expYear", "subscreasy_cvv"
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
