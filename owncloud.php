<?php
/**
 * Plugin ownCloud
 *
 * Display your ownCloud instance in Roundcube w/ auth
 *
 * @author Thomas Payen <thomas.payen@i-carre.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

class owncloud extends rcube_plugin {
  /**
   *
   * @var string
   */
  public $task = '.*';

  /**
   * (non-PHPdoc)
   *
   * @see rcube_plugin::init()
   */
  function init() {
    $rcmail = rcmail::get_instance();

    // Load the configuration
    $this->load_config();
    $this->add_texts('localization/', false);

    $this->add_hook('logout_after', array(
            $this,
            'logout_after'
    ));

    // Add the css
    $this->include_stylesheet($this->local_skin_path() . '/owncloud.css');

    // Create & register the task
    $this->register_task('owncloud');
    $this->add_button(array(
            'command' => 'owncloud',
            'class' => 'button-owncloud',
            'classsel' => 'button-owncloud button-selected',
            'innerclass' => 'button-inner',
            'label' => 'owncloud.task'
    ), 'taskbar');

    // If task is owncloud load the frame
    if ($rcmail->task == 'owncloud') {
      // Add the css for the frame
      $this->include_stylesheet($this->local_skin_path() . '/frame.css');
      $this->register_action('index', array(
              $this,
              'action'
      ));
      $this->login_owncloud();
    }
  }

  function action() {
    $rcmail = rcmail::get_instance();
    // register UI objects
    $rcmail->output->add_handlers(array(
            'owncloud_frame' => array(
                    $this,
                    'owncloud_frame'
            )
    ));
    // Load the template
    $rcmail->output->set_pagetitle($this->gettext('title'));
    $rcmail->output->send('owncloud.owncloud');
  }
  /**
   * Call after logout
   *
   * @param array $args
   */
  function logout_after($args) {
    $rcmail = rcmail::get_instance();
    $rcmail->output->set_env('owncloud_url', $rcmail->config->get('owncloud_url'));
    // Call the disconnect script to logout from owncloud
    $this->include_script('disconnect.js');
  }
  /**
   * Frame display
   *
   * @param array $attrib
   * @return string
   */
  function owncloud_frame($attrib) {
    if (! $attrib['id'])
      $attrib['id'] = 'rcmowncloudframe';

    $rcmail = rcmail::get_instance();

    $attrib['name'] = $attrib['id'];

    $rcmail->output->set_env('contentframe', $attrib['name']);
    $rcmail->output->set_env('blankpage', $attrib['src'] ? $rcmail->output->abs_url($attrib['src']) : 'program/resources/blank.gif');

    return $rcmail->output->frame($attrib);
  }
  /**
   * Login ownCloud
   */
  private function login_owncloud() {
    $rcmail = rcmail::get_instance();
    $owncloud_url = $rcmail->config->get('owncloud_url');
    // Env variables
    $rcmail->output->set_env('owncloud_username', $rcmail->user->get_username());
    $rcmail->output->set_env('owncloud_password', urlencode($this->encrypt($rcmail->get_user_password())));
    $rcmail->output->set_env('owncloud_url', $owncloud_url);

    $skin = $rcmail->config->get("skin");
    $rcmail->output->set_env('owncloud_gotourl', $owncloud_url);
    // Call the connection to owncloud script
    $this->include_script('owncloud.js');
  }
  /**
   * Encrypt using 3DES
   *
   * @param string $clear clear text input
   * @param string $key encryption key to retrieve from the configuration, defaults to 'des_key'
   * @param boolean $base64 whether or not to base64_encode() the result before returning
   * @return string encrypted text
   */
  private function encrypt($clear, $key = 'roundcube_owncloud_des_key', $base64 = true) {
    if (! $clear) {
      return '';
    }

    $rcmail = rcmail::get_instance();

    /*
     * -
     * Add a single canary byte to the end of the clear text, which
     * will help find out how much of padding will need to be removed
     * upon decryption; see http://php.net/mcrypt_generic#68082
     */
    $clear = pack("a*H2", $clear, "80");
    $ckey = $rcmail->config->get_crypto_key($key);

    if (function_exists('openssl_encrypt')) {
      $method = 'DES-EDE3-CBC';
      $opts = defined('OPENSSL_RAW_DATA') ? OPENSSL_RAW_DATA : true;
      $iv = $this->create_iv(openssl_cipher_iv_length($method));
      $cipher = $iv . openssl_encrypt($clear, $method, $ckey, $opts, $iv);
    }
    else if (function_exists('mcrypt_module_open') && ($td = mcrypt_module_open(MCRYPT_TripleDES, "", MCRYPT_MODE_CBC, ""))) {
      $iv = $this->create_iv(mcrypt_enc_get_iv_size($td));
      mcrypt_generic_init($td, $ckey, $iv);
      $cipher = $iv . mcrypt_generic($td, $clear);
      mcrypt_generic_deinit($td);
      mcrypt_module_close($td);
    }

    return $base64 ? base64_encode($cipher) : $cipher;
  }
  /**
   * Generates encryption initialization vector (IV)
   *
   * @param int Vector size
   * @return string Vector string
   */
  private function create_iv($size) {
    // mcrypt_create_iv() can be slow when system lacks entrophy
    // we'll generate IV vector manually
    $iv = '';
    for ($i = 0; $i < $size; $i ++) {
      $iv .= chr(mt_rand(0, 255));
    }

    return $iv;
  }
}