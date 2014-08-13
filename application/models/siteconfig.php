<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class SiteConfig extends CI_Model {

private $loaded;

public function __construct($id = NULL) {
    parent::__construct($id);

    // load it into memory
    $this->loaded = array();
    $cx = $this->db->query('SELECT * FROM config');
    foreach ($cx->result() as $c) {
        $this->loaded[ $c->keyword ] = $c->value;
    }
}

public function all() {
    return @$this->loaded;
}

public function get($key) {
    return @$this->loaded[$key];
}

public function set($key,$value) {
    if (array_key_exists($key,$this->loaded)) {
        $this->db->query('UPDATE config SET value=? WHERE keyword=?', array($value,$key) );
    } else {
        $this->db->query('INSERT INTO config (keyword,value) VALUES (?,?)', array($key,$value) );
    }
    $this->loaded[$key] = $value;
}


} // end of Model
