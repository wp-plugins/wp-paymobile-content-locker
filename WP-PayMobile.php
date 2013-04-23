<?php
/*
Plugin Name: WP PayMobile Content Locker
Plugin URI: http://paymobile.crivion.com
Description: WP PayMobile enables you to "lock" portions or complete posts/pages contents and ask for an SMS/Phone Call mobile micropayment to unlock.
Author: Crivion
Version: 1.1
Author URI: http://crivion.com
License: GPLv2 or later
*/
class WP_PayMobile {

	public static $IP;
	public $PayMobile_Options = array('wp_paytoread_btnstyle' => 'standard-yellow', 
							 		  'wp_paytoread_linktext' => 'Pay by SMS/Call',
							 		  'wp_paytoread_msg'      => 'Pay by Mobile to get access to the rest of the content', 
							 		  'wp_paytoread_service'  =>  0000, 
							 		  'wp_paytoread_currency' => 'USD');

	public function __construct() {

		//add install hook
		register_activation_hook(__FILE__, array($this, 'install'));

		//add shortcodes
		add_shortcode('wp_paymobile_popup', array($this, 'shortcode_popup'));
		add_shortcode('wp_paymobile_ipn', array($this, 'shortcode_ipn'));

		//ip address
		$this->IP = $this->getRealIpAddr();

		//add wp-admin page
		add_action('admin_menu', array($this, 'admin_page'));

		//hide IPN page from menu
		add_filter( 'wp_nav_menu_args', array($this, 'wpesc_nav_menu_args' ));
		add_filter( 'wp_page_menu_args', array($this, 'wpesc_nav_menu_args' ));
		add_filter( 'wp_list_pages_excludes', array($this, 'wpesc_nav_menu_args'));

	}	


	//ad installation db  tables
	public function install() {

		//install db table
		global $wpdb;

		$table_name = $wpdb->prefix . 'paymobile';

		$wpdb->query("DROP TABLE IF EXISTS $table_name");

		$sql = "CREATE TABLE $table_name (
		  sms_id mediumint(9) NOT NULL AUTO_INCREMENT,
		  sms_key VARCHAR(255) DEFAULT '' NOT NULL,
		  sms_country VARCHAR(255) DEFAULT '' NOT NULL,
		  sms_phone VARCHAR(255) DEFAULT '' NOT NULL,
		  sms_operator VARCHAR(255) DEFAULT '' NOT NULL,
		  sms_price DECIMAL( 3, 2 ) NOT NULL,
		  sms_currency VARCHAR(255) DEFAULT '' NOT NULL,
		  postID int(11) NOT NULL, 
		  ipAddress int(11) NOT NULL, 
		  sms_date int(11) NOT NULL, 
		  UNIQUE KEY sms_id (sms_id)
		);";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		//setup plugin options
		foreach($this->PayMobile_Options as $option_name => $option_value) {
		 	  update_option($option_name, $option_value);
		}

		//create sms ipn shortcode
		wp_insert_post( array(
							'post_title' => 'Paymobile IPN',
							'post_type' 	=> 'page',
							'post_name'	 => 'paymobile-ipn',
							'comment_status' => 'closed',
							'ping_status' => 'closed',
							'post_content' => '[wp_paymobile_ipn]',
							'post_status' => 'publish',
							'post_author' => 1,
							'menu_order' => 0
						));

	}


	//generate the shortcode which "locks" the content
	public function shortcode_popup($atts, $content = null) {

		global $post;

		//ipn URL
		$ipn_URL = get_bloginfo('url') . '/paymobile-ipn';
		$serviceID = get_option('wp_paytoread_service', 0000);
		$currency = get_option( 'wp_paytoread_currency', 'USD');
	
		//user paid
		if($this->did_user_pay($post->ID)) return $content;
		
		//user did not pay :: show PayGol popup
		$return = get_option('wp_paytoread_msg');

		//paymobile button style
		$btn = get_option( 'wp_paytoread_btnstyle', 'standard-yellow' );
		
		if($btn == 'standard-yellow') {
			$paymobile_submit = '<input type="image" name="pg_button" class="paygol" src="http://www.paygol.com/micropayment/img/buttons/150/yellow_en_pbm.png" border="0" alt="Make payments with PayGol: the easiest way!" title="Make payments with PayGol: the easiest way!" onClick="pg_reDirect(this.form)">';
		}elseif($btn == 'standard-red') {
			$paymobile_submit = '<input type="image" name="pg_button" class="paygol" src="http://www.paygol.com/micropayment/img/buttons/150/red_en_pbm.png" border="0" alt="Make payments with PayGol: the easiest way!" title="Make payments with PayGol: the easiest way!" onClick="pg_reDirect(this.form)">';
		}elseif ($btn == 'standard-blue') {
			$paymobile_submit = '<input type="image" name="pg_button" class="paygol" src="http://www.paygol.com/micropayment/img/buttons/150/blue_en_pbm.png" border="0" alt="Make payments with PayGol: the easiest way!" title="Make payments with PayGol: the easiest way!" onClick="pg_reDirect(this.form)">';
		}elseif(get_option("wp_paytoread_btnstyle") == 'image-btn') {
			$paymobile_submit = '<input type="image" name="pg_button" class="paygol" src="' . get_option('wp_paytoread_btnurl') . '" border="0" alt="Make payments with PayGol: the easiest way!" title="Make payments with PayGol: the easiest way!" onClick="pg_reDirect(this.form)">';
		}

		$return .= '<!-- PayGol JavaScript -->
					<script src="http://www.paygol.com/micropayment/js/paygol.js" type="text/javascript"></script> 

					<!-- PayGol Form -->
					<form name="pg_frm">
					 <input type="hidden" name="pg_serviceid" value="' . trim($serviceID) . '">
					 <input type="hidden" name="pg_currency" value="' . trim($currency) . '">
					 <input type="hidden" name="pg_name" value="WP PayMobile To Read">
					 <input type="hidden" name="pg_custom" value="' . $post->ID . '_' . $this->getRealIpAddr() . '">
					 <input type="hidden" name="pg_price" value="1">
					 <input type="hidden" name="pg_return_url" value="' . get_permalink() . '">
					 <input type="hidden" name="pg_notify_url" value="' . $ipn_URL . '">
					' . $paymobile_submit . '				 
					</form>';
		
		return $return;

	}

	//generate shortcode for IPN page
	public function shortcode_ipn() {

		global $wpdb;
		global $post;
		
		// check that the request comes from PayGol server
		if(!in_array($_SERVER['REMOTE_ADDR'],
		  array('109.70.3.48', '109.70.3.146', '109.70.3.58'))) {
		  return false;
		}
		
		// get the variables from PayGol system
		$message_id	= trim(strip_tags($_GET['message_id']));
		$service_id	= trim(strip_tags($_GET['service_id']));
		$shortcode	= trim(strip_tags($_GET['shortcode']));
		$keyword	= trim(strip_tags($_GET['keyword']));
		$message	= trim(strip_tags($_GET['message']));
		$sender	 = trim(strip_tags($_GET['sender']));
		$operator	= trim(strip_tags($_GET['operator']));
		$country	= trim(strip_tags($_GET['country']));
		$custom	 = trim(strip_tags($_GET['custom']));
		$price	 = trim(strip_tags($_GET['price']));
		$currency	= trim(strip_tags($_GET['currency']));

		if(!stristr($custom, "_")) return false;

		$custom = explode("_", $custom);

		//update DB
		$db_paymobile = array( 'sms_key' => $message,
								'sms_phone' => $sender,
								'sms_operator'=> $operator,
								'sms_country' => $country,
								'sms_price' => $price, 
								'sms_currency' => $currency,
								'postID' => $custom[0],
								'ipAddress' => $custom[1],
								'sms_date' => time());

		$wpdb->insert($wpdb->prefix . 'paymobile', $db_paymobile);

		return $wpdb->insert_id;

	}

	//function to tell whether user has paid for this post
	public function did_user_pay($postID) {
		global $wpdb;

		$rs = $wpdb->get_row("SELECT COUNT(*) as thisPost FROM " . $wpdb->prefix . "paymobile 
							WHERE ipAddress = '" . $this->getRealIpAddr() . "' 
							AND postID = '" . $postID . "'");

		return $rs->thisPost;
	}	

	//get user IP
	public function getRealIpAddr()
	{
	    if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
	    {
	      $ip=$_SERVER['HTTP_CLIENT_IP'];
	    }
	    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
	    {
	      $ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
	    }
	    else
	    {
	      $ip=$_SERVER['REMOTE_ADDR'];
	    }

	    return ip2long($ip);
	}

    //wp-admin options
	public function admin_page() {
	     add_menu_page('WP PayMobile', 'WP PayMobile', 'add_users', 'WP_PayMobile',  array($this, 'options_page'), plugins_url('wp-paymobile-content-locker/assets/lock.png'));
	     add_submenu_page( 'WP_PayMobile', 'WP PayMobile Stats', 'Statistics', 'add_users', 'WP_PayMobile_Stats', array($this, 'statistics_page'));
	     add_submenu_page( 'WP_PayMobile', 'WP PayMobile Install Guide', 'Install Guide', 'add_users', 'WP_PayMobile_Install', array($this, 'install_page'));
	}

	public function options_page() {
	//delete btn
	if(isset($_GET['do']) AND ($_GET['do'] == 'removeBtn')) {
		delete_option('wp_paytoread_btnurl');
	}

	public function install_page() {
		file_get_contents('documentation.html');
	}
	
	//update options	
	if(isset($_POST['sb_paytoread'])) {
		
		//if file uploaded
		if(isset($_FILES)) {
			if($_FILES['button-file']['error'] == 0) {
				if(getimagesize($_FILES["button-file"]["tmp_name"])) {
					$upload = wp_upload_bits($_FILES["button-file"]["name"], null, file_get_contents($_FILES["button-file"]["tmp_name"]));
					if(isset($upload['error']) AND !empty($upload['error'])) {
						printf('<div class="updated bellow-h2">%s</div>', $upload['error']);
					}else{
						update_option('wp_paytoread_btnurl', $upload['url']);
					}
				}else{
					echo '<div class="updated bellow-h2">Invalid image file</div>';
				}
			}
		}
		
		foreach($this->PayMobile_Options as $option_name => $option_value) {
			update_option($option_name, $_POST[$option_name]);
		}
		
		echo '<div class="updated bellow-h2">Options saved</div>';
		
	}	
		
	//get options
	$btn_style = get_option('wp_paytoread_btnstyle');
	$link_text = get_option('wp_paytoread_linktext');
	$message_toshow = get_option("wp_paytoread_msg");
	$btn_url = get_option('wp_paytoread_btnurl');
	$wp_paytoread_service = get_option('wp_paytoread_service', '00000');
	$wp_paytoread_currency = get_option( 'wp_paytoread_currency', 'USD');
	?>
	<div class="wrap">
		<img src="<?php echo plugins_url('WP-PayMobile/assets/lock-icon.png'); ?>" class="alignleft" style="margin-top:5px;margin-right:5px;"/>
		<h2>WP PayMobile Options</h2>
		
		<div style="clear:both;width:500px;border-top:1px solid #EFEFEF;margin-top:10px;margin-bottom:10px;"></div>
		
		<form method="POST" action="" enctype="multipart/form-data">
			
			<table width="550">
				<tr style="background-color:#efefef;">
					<td>PayGol Service ID</td>
					<td>
						<input type="text" name="wp_paytoread_service" value="<?php echo $wp_paytoread_service ?> " />
					</td>
				</tr>
				<tr style="background-color:#efefef;">
					<td>PayGol Currency (ie. USD, EUR, etc)</td>
					<td>
						<input type="text" name="wp_paytoread_currency" value="<?php echo $wp_paytoread_currency ?> " />
					</td>
				</tr>
				<tr style="background-color:#efefef;">
					<td>Pay By Mobile Link Style:</td> 
		    	<td>	
					<input type="radio" name="wp_paytoread_btnstyle" id="wp_paytoread_btnstyle" value="image-btn" <?php if($btn_style == 'image-btn') echo "checked=\"\""; ?>/> Custom Image Button <br />
					<input type="radio" name="wp_paytoread_btnstyle" id="wp_paytoread_btnstyle" value="standard-yellow" <?php if($btn_style == 'standard-yellow') echo "checked=\"\""; ?>/> Standard Yellow &nbsp;&nbsp; <br />
					<input type="radio" name="wp_paytoread_btnstyle" id="wp_paytoread_btnstyle" value="standard-red" <?php if($btn_style == 'standard-red') echo "checked=\"\""; ?>/> Standard Red &nbsp;&nbsp; <br />
					<input type="radio" name="wp_paytoread_btnstyle" id="wp_paytoread_btnstyle" value="standard-blue" <?php if($btn_style == 'standard-blue') echo "checked=\"\""; ?>/> Standard Blue &nbsp;&nbsp; <br />
				</td>
				</tr>
				<tr>
					<td>Button Image File<br/>(if custom button selected)</td>
					<td><?php if($btn_url) printf('<img src="%s" alt="image file" />', $btn_url); ?> 
						<?php if($btn_url) printf('<br/><a href="%s">%s</a>', 'admin.php?page=WP_PayMobile&do=removeBtn', 'Remove button'); ?>
						<br/>
						<input type="file" name="button-file" /></td>
				</tr>
				<tr style="background-color:#efefef;">
					<td>Message to view<br/>for encouraging users to pay by mobile</td>
					<td>
						<textarea name="wp_paytoread_msg" rows="8" cols="33"><?php echo $message_toshow ?></textarea>
					</td>
				</tr>
				<tr>
					<td>&nbsp;</td>
					<td><input type="submit" name="sb_paytoread" value="Save Options" class="button button-primary"/></td>
				</tr>
			</table>
			
		</form>
	</div>
	<?php
}

	public function statistics_page() {
		global $wpdb;

		if(isset($_GET['delete'])) {
			
			$id = intval($_GET['delete']);
			
			$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->prefix."paymobile WHERE sms_id = %d", $id));
			
			if($wpdb->rows_affected) echo '<div class="updated bellow-h2">Item removed</div>';
		}


		?>
		<div class="wrap">
		<img src="<?php echo plugins_url('wp-paymobile-content-locker/assets/lock-icon.png'); ?>" class="alignleft" style="margin-top:5px;margin-right:5px;"/>
		<h2>WP PayMobile Statistics</h2>
		
		<div style="clear:both;width:500px;border-top:1px solid #EFEFEF;margin-top:10px;margin-bottom:10px;"></div>

		<form method="POST" action="">
			Select month : <select name="month">
				<?php 
				$months = array(
							    1 => 'January',
							    2 => 'February',
							    3 => 'March',
							    4 => 'April',
							    5 => 'May',
							    6 => 'June',
							    7 => 'July ',
							    8 => 'August',
							    9 => 'September',
							    10 => 'October',
							    11 => 'November',
							    12 => 'December');

				foreach($months as $month_number => $month) {
					if(date("n") == $month_number) {
						echo '<option value="'.$month_number.'" selected="">' . $month . '</option>';
					}else{
						echo '<option value="'.$month_number.'">' . $month . '</option>';
					}
				}
				?>
			</select>
			<input type="submit" name="sb" value="OK" class="button" />
		</form>

		<div style="clear:both;width:500px;border-top:1px solid #EFEFEF;margin-top:10px;margin-bottom:10px;"></div>

		<?php
		$the_month = isset($_POST['month']) ? intval($_POST['month']) : date("n");
		$the_year = date("Y");

		$query = $wpdb->get_results(
					$wpdb->prepare("SELECT ".$wpdb->prefix."paymobile.*, ".$wpdb->prefix."posts.post_title, 
									MONTH( FROM_UNIXTIME( sms_date ) ) AS the_month, 
									YEAR( FROM_UNIXTIME( sms_date ) ) AS the_year 
									FROM ".$wpdb->prefix."paymobile, ".$wpdb->prefix."posts WHERE 
									".$wpdb->prefix."paymobile.postID = ".$wpdb->prefix."posts.ID 
									HAVING the_month = '%d' AND the_year = '%d'", $the_month, $the_year));
		?>

		<table width="640" class="wp-list-table widefat fixed posts">
			<thead>
				<tr>
					<th>Post</th>
					<th>IP</th>
					<th>Date</th>
					<th>Phone</th>
					<th>Operator</th>
					<th>Price/Currency</th>
					<th>&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php
				if(count($query)) {
					foreach($query as $o) {
						echo '<tr>
								<td><a href="' . get_permalink( $o->postID) . '" target="_blank">'.$o->post_title.'</a></td>
								<td>'.long2ip($o->ipAddress).'</td>
								<td>'.date("jS F Y, H:iA", $o->sms_date).'</td>
								<td>'.$o->sms_phone.'</td>
								<td>'.$o->sms_operator.'</td>
								<td>'.$o->sms_price.''.$o->sms_currency.'</td>
								<td><a href="admin.php?page=WP_PayMobile_Stats&delete='.$o->sms_id.'" class="submitdelete deletion">Remove</a></td>
							  </tr>';
					}
				}
				?>
			</tbody>
		</table>

		</div>
		<?php
	}

	 //Menu filtering
	public function wpesc_nav_menu_args( $args = '' )
	{
	    global $wpdb;

	    $ipn_page_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_name = 'paymobile-ipn'");

		$args['exclude'] = $ipn_page_id;
		
		return $args;
	}

}

$WP_PayMobile = new WP_PayMobile;