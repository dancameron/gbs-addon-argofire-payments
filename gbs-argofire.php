<?php
/*
Plugin Name: Group Buying Payment Processor - ArgoFire
Version: 1.1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: ArgoFire Payments Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_argofire');

function gb_load_argofire() {
	require_once('groupBuyingArgofire.class.php');
}