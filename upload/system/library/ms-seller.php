<?php
final class MsSeller {
	const MS_SELLER_STATUS_ACTIVE = 1;
	const MS_SELLER_STATUS_TOBEACTIVATED = 2;
	const MS_SELLER_STATUS_TOBEAPPROVED = 3;
	const MS_SELLER_STATUS_DISABLED = 4;
	const MS_SELLER_STATUS_INACTIVE = 5;
		
	private $isSeller = FALSE; 
	private $nickname;
	private $description;
	private $company;
	private $country_id;
	private $avatar_path;
	private $seller_status_id;
	private $paypal;
	
  	public function __construct($registry) {
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->request = $registry->get('request');
		$this->session = $registry->get('session');
		$this->registry = $registry;
		$this->language = $registry->get('language');
		if (isset($this->session->data['customer_id'])) {
			//TODO 
			//$seller_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "ms_seller WHERE seller_id = '" . (int)$this->session->data['customer_id'] . "' AND seller_status_id = '1'");
			$seller_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "ms_seller WHERE seller_id = '" . (int)$this->session->data['customer_id'] . "'");			
			
			if ($seller_query->num_rows) {
				$this->isSeller = TRUE;
				$this->nickname = $seller_query->row['nickname'];
				$this->description = $seller_query->row['description'];
				$this->company = $seller_query->row['company'];
				$this->country_id = $seller_query->row['country_id'];
				$this->avatar_path = $seller_query->row['avatar_path'];
				$this->seller_status_id = $seller_query->row['seller_status_id'];
				$this->paypal = $seller_query->row['paypal'];
			}
  		}
  		
		require_once(DIR_SYSTEM . 'library/ms-product.php');
		$this->msProduct = new MsProduct($registry);  		
	}
		
  	public function isCustomerSeller($customer_id) {
		$sql = "SELECT COUNT(*) as 'total'
				FROM `" . DB_PREFIX . "ms_seller`
				WHERE seller_id = " . (int)$customer_id;
		
		$res = $this->db->query($sql);
		
		if ($res->row['total'] == 0)
			return FALSE;
		else
			return TRUE;	  		
  	}
  	
	public function getSellerData($seller_id) {
		$sql = "SELECT * 
				FROM `" . DB_PREFIX . "ms_seller`
				WHERE seller_id = " . (int)$seller_id;
		
		$res = $this->db->query($sql);
		
		return $res->row;
	}
	
	public function getSellerName($seller_id) {
		$sql = "SELECT firstname as 'firstname'
				FROM `" . DB_PREFIX . "customer`
				WHERE customer_id = " . (int)$seller_id;
		
		$res = $this->db->query($sql);
		
		return $res->row['firstname'];
	}	
	
	public function getSellerEmail($seller_id) {
		$sql = "SELECT email as 'email' 
				FROM `" . DB_PREFIX . "customer`
				WHERE customer_id = " . (int)$seller_id;
		
		$res = $this->db->query($sql);
		
		return $res->row['email'];
	}
		
	//TODO
	public function getSellerStatus($seller_status_id = NULL) {
		$result = array(
			self::MS_SELLER_STATUS_ACTIVE => $this->language->get('ms_seller_status_active'),
			self::MS_SELLER_STATUS_TOBEACTIVATED => $this->language->get('ms_seller_status_activation'),
			self::MS_SELLER_STATUS_TOBEAPPROVED => $this->language->get('ms_seller_status_approval'),
			self::MS_SELLER_STATUS_DISABLED => $this->language->get('ms_seller_status_disabled'),
		);		
		
		if ($seller_status_id) {
			return $result[$seller_status_id];
		} else {
			return $result;
		}
	}		
		
	public function getTotalSellerProducts($seller_id) {
		$sql = "SELECT COUNT(*) as 'total'
				FROM `" . DB_PREFIX . "ms_product` p
				WHERE p.seller_id = " . (int)$seller_id;
		
		$res = $this->db->query($sql);
		
		return $res->row['total'];		
	}		
		
	public function getSellerProducts($seller_id, $sort) {
		$sql = "SELECT c.product_id, name, date_added, status as status_id, number_sold, review_status_id 
				FROM `" . DB_PREFIX . "product_description` a
				INNER JOIN `" . DB_PREFIX . "product` b
					ON a.product_id = b.product_id 
				INNER JOIN `" . DB_PREFIX . "ms_product` c
					ON b.product_id = c.product_id
				WHERE c.seller_id = " . (int)$seller_id . "
				AND a.language_id = " . $this->config->get('config_language_id'). "
        		ORDER BY {$sort['order_by']} {$sort['order_way']}" 
        		. ($sort['limit'] ? " LIMIT ".(int)(($sort['page'] - 1) * $sort['limit']).', '.(int)($sort['limit']) : '');				
		
		$res = $this->db->query($sql);
		
		
		$review_statuses = $this->msProduct->getProductStatusArray();
		foreach ($res->rows as &$row) {
			$row['review_status'] = $review_statuses[$row['review_status_id']];
			$row['status'] = $row['status_id'] ? $this->language->get('text_yes') : $this->language->get('text_no');
		}
		
		return $res->rows;
	}		
		
	public function getReservedAmount($seller_id) {
		$sql = "SELECT SUM(amount - (amount*commission/100)) as total
				FROM `" . DB_PREFIX . "ms_transaction`
				WHERE seller_id = " . (int)$seller_id . "
				AND type = " . MsTransaction::MS_TRANSACTION_WITHDRAWAL . ";
				AND transaction_status_id = " . MsTransaction::MS_TRANSACTION_STATUS_PENDING;
		
		$res = $this->db->query($sql);
		
		return $res->row['total'];
	}		
		
	public function createSeller($data) {
		if (isset($data['sellerinfo_avatar_name'])) {
			$image = MsImage::byName($this->registry, $data['sellerinfo_avatar_name']);
			$image->move('I');
			$avatar = $image->getName();
		} else {
			$avatar = '';
		}		
		
		$sql = "INSERT INTO " . DB_PREFIX . "ms_seller
				SET seller_id = " . (int)$data['seller_id'] . ",
					seller_status_id = " . (int)$data['seller_status_id'] . ",
					commission = " . (float)$this->config->get('msconf_seller_commission') . ",
					nickname = '" . $this->db->escape($data['sellerinfo_nickname']) . "',
					description = '" . $this->db->escape($data['sellerinfo_description']) . "',
					company = '" . $this->db->escape($data['sellerinfo_company']) . "',
					country_id = " . (int)$data['sellerinfo_country'] . ",
					paypal = '" . $this->db->escape($data['sellerinfo_paypal']) . "',
					avatar_path = '" . $this->db->escape($avatar) . "',
					date_created = NOW()";
		
		$this->db->query($sql);
		
		/*
		$message = sprintf($this->language->get('ms_mail_greeting'), $this->customer->getFirstName()) . "\n\n";
		$message .= sprintf($this->language->get('ms_account_sellerinfo_mail_account_thankyou'), $this->config->get('config_name')) . "\n\n";		
		
		$v = $this->config->get('msconf_seller_validation');

		switch ($v) {
			// activation link
			case MS_SELLER_VALIDATION_ACTIVATION:
				$subject = sprintf($this->language->get('ms_account_sellerinfo_mail_account_pleaseactivate_subject'), $this->config->get('config_name'));
				$message .= sprintf($this->language->get('ms_account_sellerinfo_mail_account_pleaseactivate_message'), $this->config->get('config_name')) . "\n\n";
				$message .= 'http://dolboeb.eu/' . "\n\n";
				break;
				
			// manual approval
			case MS_SELLER_VALIDATION_APPROVAL:
				$subject = sprintf($this->language->get('ms_account_sellerinfo_mail_account_needsapproval_subject'), $this->config->get('config_name'));
				$message .= sprintf($this->language->get('ms_account_sellerinfo_mail_account_needsapproval_message'), $this->config->get('config_name')) . "\n\n";			
				break;
				
			// no validation
			case MS_SELLER_VALIDATION_NONE:
			default:
				$subject = sprintf($this->language->get('ms_account_sellerinfo_mail_account_created_subject'), $this->config->get('config_name'));
				$message .= sprintf($this->language->get('ms_account_sellerinfo_mail_account_created_message'), $this->config->get('config_name')) . "\n\n";
				break;								
		}
		
		$message .= sprintf($this->language->get('ms_mail_regards'), HTTP_SERVER) . "\n" . $this->config->get('config_name');

		$mail = new Mail();
		$mail->protocol = $this->config->get('config_mail_protocol');
		$mail->parameter = $this->config->get('config_mail_parameter');
		$mail->hostname = $this->config->get('config_smtp_host');
		$mail->username = $this->config->get('config_smtp_username');
		$mail->password = $this->config->get('config_smtp_password');
		$mail->port = $this->config->get('config_smtp_port');
		$mail->timeout = $this->config->get('config_smtp_timeout');				
		$mail->setTo($this->customer->getEmail());
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender($this->config->get('config_name'));
		$mail->setSubject($subject);
		$mail->setText($message);
		$mail->send();
		*/
		/*
		// Send to main admin email if new account email is enabled
		if ($this->config->get('config_account_mail')) {
			$mail->setTo($this->config->get('config_email'));
			$mail->send();
			
			// Send to additional alert emails if new account email is enabled
			$emails = explode(',', $this->config->get('config_alert_emails'));
			
			foreach ($emails as $email) {
				if (strlen($email) > 0 && preg_match('/^[^\@]+@.*\.[a-z]{2,6}$/i', $email)) {
					$mail->setTo($email);
					$mail->send();
				}
			}
		}
		*/
	}
	
	public function nicknameTaken($nickname) {
		$sql = "SELECT nickname
				FROM `" . DB_PREFIX . "ms_seller` p
				WHERE p.nickname = '" . $this->db->escape($nickname) . "'";
		
		$res = $this->db->query($sql);
		
		return $res->num_rows;
	}	
	
	public function editSeller($data) {
		$seller_id = (int)$data['seller_id'];

		$old_avatar = $this->getSellerAvatar($seller_id);
		
		if (!isset($data['sellerinfo_avatar_name']) || ($old_avatar['avatar'] != $data['sellerinfo_avatar_name'])) {
			$image = MsImage::byName($this->registry, $old_avatar['avatar']);
			$image->delete('I');				
		}
		
		if (isset($data['sellerinfo_avatar_name'])) {
			$image = MsImage::byName($this->registry, $data['sellerinfo_avatar_name']);
			$image->move('I');
			$avatar = $image->getName();
		} else {
			$avatar = '';
		}

		$sql = "UPDATE " . DB_PREFIX . "ms_seller
				SET description = '" . $this->db->escape($data['sellerinfo_description']) . "',
					company = '" . $this->db->escape($data['sellerinfo_company']) . "',
					country_id = " . (int)$data['sellerinfo_country'] . ",
					paypal = '" . $this->db->escape($data['sellerinfo_paypal']) . "',
					avatar_path = '" . $avatar . "'
				WHERE seller_id = " . (int)$seller_id;
		
		$this->db->query($sql);	
	}		
		
	public function getBalanceForSeller($seller_id) {
		$sql = "SELECT SUM(amount - (amount*commission/100)) as total
				FROM `" . DB_PREFIX . "ms_transaction`
				WHERE seller_id = " . (int)$seller_id . " 
				AND transaction_status_id != " . MsTransaction::MS_TRANSACTION_STATUS_CLOSED;
		
		$res = $this->db->query($sql);
		
		return (float)$res->row['total'];
	}
		
	public function getCommissionForSeller($seller_id) {
		$sql = "SELECT 	commission
				FROM `" . DB_PREFIX . "ms_seller`
				WHERE seller_id = " . (int)$seller_id; 

		$res = $this->db->query($sql);

		if (isset($res->row['commission']))
			return $res->row['commission'];
		else
			return 0;
	}
		
	public function getSellerAvatar($seller_id) {
		$query = $this->db->query("SELECT avatar_path as avatar FROM " . DB_PREFIX . "ms_seller WHERE seller_id = '" . (int)$seller_id . "'");
		
		return $query->row;
	}		
		
		
		
		
		
		
		
  	public function login($email, $password, $override = false) {
  		/*
		if ($override) {
			$seller_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer where LOWER(email) = '" . $this->db->escape(strtolower($email)) . "' AND status = '1'");
		} elseif (!$this->config->get('config_customer_approval')) {
			$seller_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer WHERE LOWER(email) = '" . $this->db->escape(strtolower($email)) . "' AND password = '" . $this->db->escape(md5($password)) . "' AND status = '1'");
		} else {
			$seller_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer WHERE LOWER(email) = '" . $this->db->escape(strtolower($email)) . "' AND password = '" . $this->db->escape(md5($password)) . "' AND status = '1' AND approved = '1'");
		}
		
		if ($seller_query->num_rows) {
			$this->session->data['customer_id'] = $seller_query->row['customer_id'];	
		    
			if ($seller_query->row['cart'] && is_string($seller_query->row['cart'])) {
				$cart = unserialize($seller_query->row['cart']);
				
				foreach ($cart as $key => $value) {
					if (!array_key_exists($key, $this->session->data['cart'])) {
						$this->session->data['cart'][$key] = $value;
					} else {
						$this->session->data['cart'][$key] += $value;
					}
				}			
			}

			if ($seller_query->row['wishlist'] && is_string($seller_query->row['wishlist'])) {
				if (!isset($this->session->data['wishlist'])) {
					$this->session->data['wishlist'] = array();
				}
								
				$wishlist = unserialize($seller_query->row['wishlist']);
			
				foreach ($wishlist as $product_id) {
					if (!in_array($product_id, $this->session->data['wishlist'])) {
						$this->session->data['wishlist'][] = $product_id;
					}
				}			
			}
									
			$this->customer_id = $seller_query->row['customer_id'];
			$this->firstname = $seller_query->row['firstname'];
			$this->lastname = $seller_query->row['lastname'];
			$this->email = $seller_query->row['email'];
			$this->telephone = $seller_query->row['telephone'];
			$this->fax = $seller_query->row['fax'];
			$this->newsletter = $seller_query->row['newsletter'];
			$this->customer_group_id = $seller_query->row['customer_group_id'];
			$this->address_id = $seller_query->row['address_id'];
          	
			$this->db->query("UPDATE " . DB_PREFIX . "customer SET ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "' WHERE customer_id = '" . (int)$seller_query->row['customer_id'] . "'");
			
	  		return true;
    	} else {
      		return false;
    	}
    	*/
  	}
  	
	public function logout() {
		/*
		unset($this->session->data['customer_id']);

		$this->customer_id = '';
		$this->firstname = '';
		$this->lastname = '';
		$this->email = '';
		$this->telephone = '';
		$this->fax = '';
		$this->newsletter = '';
		$this->customer_group_id = '';
		$this->address_id = '';
		*/
  	}
  
  	public function getBalance() {
		//$query = $this->db->query("SELECT SUM(amount) AS total FROM " . DB_PREFIX . "customer_transaction WHERE customer_id = '" . (int)$this->customer_id . "'");
	
		return $query->row['total'];
  	}	
		
  	public function getRewardPoints() {
		//$query = $this->db->query("SELECT SUM(points) AS total FROM " . DB_PREFIX . "customer_reward WHERE customer_id = '" . (int)$this->customer_id . "'");
	
		return $query->row['total'];	
  	}
  	
  	public function getNickname() {
  		return $this->nickname;
  	}

  	public function getCompany() {
  		return $this->company;
  	}
  	
  	public function getCountryId() {
  		return $this->country_id;
  	}

  	public function getDescription() {
  		return $this->description;
  	}
  	
  	public function getAvatarPath() {
  		return $this->avatar_path;
  	}
  	
  	public function getStatus() {
  		return $this->seller_status_id;
  	}

  	public function getPaypal() {
  		return $this->paypal;
  	}
  	
  	public function isSeller() {
  		return $this->isSeller;
  	}
}
?>