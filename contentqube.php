<?php

function cqfimp_api_call($endpoint, $method, $postfields = '', $headers = array()) {
    $args = array(
        'method' => $method,
        'redirection' => 10,
        'timeout' => 30,
        'httpversion' => '1.1',
        'headers' => $headers);

    if ($method == 'POST') {
        $args['body'] = $postfields;
    }
    $response = wp_remote_request($endpoint, $args);
    return $response;
}

function cqfimp_login($username, $password) {
    return cqfimp_api_call(CQFIMP_LOGIN_URL . "/login", "POST", json_encode(array('username' => $username, 'password' => $password)));
}

function cqfimp_get_categories($access_token) {
    return cqfimp_api_call(CQFIMP_API_URL . "/api/v1/categories?show_my_only=true", "GET", "", array("Authorization" => $access_token));
}

?>