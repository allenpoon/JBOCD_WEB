<?php defined('BASEPATH') OR exit('No direct script access allowed');

ini_set('display_errors', 'On');
error_reporting(E_ALL);

class Login_model extends CI_Model {

	private $token;

	public function __construct(){
		parent::__construct();
		$this->load->database('default');
	}

	public function getCSRF(){
		return $this->token;
	}
	
	public function refreshToken($id){
		$this->db->query(
			'REPLACE INTO `token` (`id`,`timestamp`, `token`, `session`) VALUES(?, CURRENT_TIMESTAMP, ?, ?)', 
			array($id, $this->token, session_id()));
		return $id;
	}
	
	public function authenticate($login, $pw, $session_id){
		if(!isset($login) || !isset($pw) || ($login == '') || ($pw=='')) {
			$this->session->set_userdata('login_error', array('message'=>'Login error!'));
			return -2;
		}
		if(($login == '') || ($pw=='')) return -2;
		$result = $this->db->query('SELECT * FROM `auth` WHERE `login` = ?', array($login));
		if($result->num_rows() == 1){
			$result = $result->row();
			if(hash('sha1', $pw) == $result->pw) {
				$this->token = hash("sha512",mt_rand(0,mt_getrandmax()));
				$this->session->set_userdata('login_data', array('id'=>$this->login_model->refreshToken($result->id)));
				return $result->id;
			}
			$this->session->set_userdata('login_error', array('message'=>'Please enter login credentials!'));
			return false;
		} else {
			$this->session->set_userdata('login_error', array('message'=>'Please enter login credentials!'));
			return false;
		}
	}
	
	public function isAuthenticated(){
		$data = $this->session->userdata('login_data');
		$sid = session_id();
		if(isset($data) && isset($sid)){
			$query = $this->db->query('SELECT * FROM `token` WHERE `id` = ? AND `session` = ?', array($data['id'], $sid));
			if($query->num_rows() == 1){
				$result = $query->row();
				$this->token = $result->token;
				$ts = new DateTime($result->timestamp);
				$now = new DateTime();
				if(($now->getTimestamp() - $ts->getTimestamp()) >= 3600){
					return false;
				}else{
					return $data['id'];
				}
			}else{
				return false;
			}
		}else{
			return false;
		}
	}
	
	public function revokeToken(){
		if($this->session->userdata('login_data')){
			$data = $this->session->userdata('login_data');
			$sid = session_id();
			$this->db->query('DELETE FROM `token` WHERE `id` = ? AND `session` = ?', array($data['id'], $sid));
			$this->session->unset_userdata('login_data');
			//$this->session->destroy_session();
		}
	}
}

?>
