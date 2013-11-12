<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class User extends DataMapper {

var $table = 'users';
var $has_one = array();
var $has_many = array();
var $default_order_by = array('username');


/*
 * checkPassword($username,$password)
 * Check the Users table, fetch a user, and validate their password
 * Return is NULL if nonexistent username, FALSE if bad password, or else a User instance if it checked out
 */
public static function checkPassword($username,$password) {
    // no user/pass? automatic fail
    if (! $username or ! $password) return NULL;

    // fetch the user and verify that one exists
    $user = new User();
    $user->where('username', strtolower($username) )->get();
    if (! $user->username) return NULL;

    // extract the first 8 bytes as the salt, scramble it, see if it matches what's stored
    $salt  = substr($user->password,0,8);
    $crypt = $salt . md5($salt . $password);
    if ($crypt == $user->password) return $user;
    return FALSE;
}


/*
 * encryptPassword($password)
 * return a scrambled hash of the password, suitable for use with User::checkPassword()
 */
public static function encryptPassword($password) {
    $salt  = substr(md5(mt_rand()),0,8); // generate 8 random bytes for a salt, well not entirely random but very good
    $crypt = $salt . md5($salt . $password);
    return $crypt;
}



/*
 * validateUsername($username)
 * return TRUE or FALSE indicating whether the given username is a valid one
 * valid usernames are email addresses, though "admin" is special
 */
public static function validateUsername($username) {
    return filter_var($username, FILTER_VALIDATE_EMAIL);
}



} // end of Class

