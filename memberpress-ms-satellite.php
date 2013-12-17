<?php
/*
Plugin Name: MemberPress Multisite Satelite
Plugin URI: http://www.memberpress.com/
Description: Allows this site's content to be restricted based on rules in the root blog
Version: 0.0.1
Author: Caseproof, LLC
Author URI: http://caseproof.com/
Text Domain: memberpress
Copyright: 2004-2014, Caseproof, LLC
*/

if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

class MeprMsSatellite {
  public function __construct() {
    if(is_main_site() or preg_match('!(wp-admin|wp-login|wp-include|unauthorized|.*\.php)!', $_SERVER['REQUEST_URI'] )) {
      return;
    }

    add_action( 'init', array($this, 'check_access') );  
  }

  public function check_access() {
    $rules = $this->get_uri_rules();

    if( !empty($rules) ) {
      $access_list = $this->get_access_list($rules);

      // If the access list is empty then we determine
      // that there aren't any applicable rules here
      if(!empty($access_list) and !$this->user_has_access($access_list)) {
        $this->unauthorized();
      }
    }
  }

  private function unauthorized() {
    if(!is_user_logged_in()) {
      $url = home_url( "wp-login.php?redirect_to=".urlencode($this->get_full_url()) );
    }
    else {
      $url = home_url( "unauthorized/" );
    }

    wp_redirect($url);
    exit;
  }

  // GET APPLICABLE RULES
  private function get_uri_rules() {
    global $wpdb;

    $query = "SELECT p.ID, " .
                    "pm_content.meta_value AS match_url, " .
                    "pm_regexp.meta_value AS is_regexp, " .
                    "pm_access.meta_value AS access " .
              "FROM {$wpdb->base_prefix}posts p " .
              "JOIN {$wpdb->base_prefix}postmeta pm_type " .
                "ON pm_type.post_id=p.ID " .
               "AND pm_type.meta_key='_mepr_rules_type' " .
              "JOIN {$wpdb->base_prefix}postmeta pm_content " .
                "ON pm_content.post_id=p.ID " .
               "AND pm_content.meta_key='_mepr_rules_content' " .
              "JOIN {$wpdb->base_prefix}postmeta pm_access " .
                "ON pm_access.post_id=p.ID " .
               "AND pm_access.meta_key='_mepr_rules_access' " .
              "JOIN {$wpdb->base_prefix}postmeta pm_regexp " .
                "ON pm_regexp.post_id=p.ID " .
               "AND pm_regexp.meta_key='_is_mepr_rules_content_regexp' " .
              "WHERE p.post_type='memberpressrule' " .
                "AND p.post_status='publish' " .
                "AND pm_type.meta_value='custom'";

    return $wpdb->get_results($query); 
  }

  private function get_access_list($rules) {
    $mp_url = $_SERVER['REQUEST_URI'];
    $access_list = array();

    // SEE IF RULES APPLY TO CURRENT URI
    foreach($rules as $rule) {
      if(($rule->is_regexp and preg_match('!'.$rule->match_url.'!',$mp_url)) or
         (!$rule->is_regexp and $rule->match_url==$mp_url)) {
        $product_ids = unserialize($rule->access);
        $access_list = array_unique(array_merge($access_list,(array)$product_ids));
      }
    }

    return $access_list;
  }

  private function user_has_access($access_list) {
    global $wpdb;

    $has_access = false;

    $user = wp_get_current_user();
    $uid = (int)$user->ID;

    if( $uid ) { // User's logged in
      if( is_super_admin() ) {
        $has_access = true;
      }
      else {
        // SEE IF THE USER IS ACTIVE ON ANY PRODUCTS THAT WOULD GRANT THE USER ACCESS
        // TODO: We want to follow the rule drips and expires here 
        $query = "SELECT COUNT(*) FROM {$wpdb->base_prefix}mepr_transactions AS tr " .
                  "WHERE tr.expires_at > NOW() " .
                    "AND tr.user_id=%d " .
                    "AND tr.product_id IN (" . implode(',',$access_list) . ")";
        $query = $wpdb->prepare( $query, $uid );
        $has_access = $wpdb->get_var($query);
      }
    }

    return $has_access;
  }

  private function get_full_url() {
    return (empty($_SERVER['HTTPS'])?'http':'https') . '://' .  $_SERVER['HTTP_HOST'] .
           (((int)$_SERVER['SERVER_PORT']!=80)?':'.$_SERVER['SERVER_PORT']:'') .
           $_SERVER['REQUEST_URI'];
  }
}

new MeprMsSatellite();

