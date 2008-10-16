<?php
/**
 * Log-in form for the "member" authentication method
 * @package sapphire
 * @subpackage security
 */
class MemberLoginForm extends LoginForm {

	protected $authenticator_class = 'MemberAuthenticator';
	
	/**
	 * Constructor
	 *
	 * @param Controller $controller The parent controller, necessary to
	 *                               create the appropriate form action tag.
	 * @param string $name The method on the controller that will return this
	 *                     form object.
	 * @param FieldSet|FormField $fields All of the fields in the form - a
	 *                                   {@link FieldSet} of {@link FormField}
	 *                                   objects.
	 * @param FieldSet|FormAction $actions All of the action buttons in the
	 *                                     form - a {@link FieldSet} of
	 *                                     {@link FormAction} objects
	 * @param bool $checkCurrentUser If set to TRUE, it will be checked if a
	 *                               the user is currently logged in, and if
	 *                               so, only a logout button will be rendered
	 * @param string $authenticatorClassName Name of the authenticator class that this form uses.
	 */
	function __construct($controller, $name, $fields = null, $actions = null,
											 $checkCurrentUser = true) {

		// This is now set on the class directly to make it easier to create subclasses
		// $this->authenticator_class = $authenticatorClassName;

		$customCSS = project() . '/css/member_login.css';
		if(Director::fileExists($customCSS)) {
			Requirements::css($customCSS);
		}

		if(isset($_REQUEST['BackURL'])) {
			$backURL = $_REQUEST['BackURL'];
		} else {
			$backURL = Session::get('BackURL');
		}

		if($checkCurrentUser && Member::currentUserID()) {
			$fields = new FieldSet();
			$actions = new FieldSet(new FormAction("logout", _t('Member.BUTTONLOGINOTHER', "Log in as someone else")));
		} else {
			if(!$fields) {
				$fields = new FieldSet(
					new HiddenField("AuthenticationMethod", null, $this->authenticator_class, $this),
					new TextField("Email", _t('Member.EMAIL'),
						Session::get('SessionForms.MemberLoginForm.Email'), null, $this),
					new EncryptField("Password", _t('Member.PASSWORD'), null, $this)
				);
				if(Security::$autologin_enabled) {
					$fields->push(new CheckboxField(
						"Remember", 
						_t('Member.REMEMBERME', "Remember me next time?"),
						Session::get('SessionForms.MemberLoginForm.Remember'), 
						$this
					));
				}
			}
			if(!$actions) {
				$actions = new FieldSet(
					new FormAction('dologin', _t('Member.BUTTONLOGIN', "Log in")),
					new LiteralField('forgotPassword', '<p><a href="Security/lostpassword">' . _t('Member.BUTTONLOSTPASSWORD', "I've lost my password") . '</a></p>')
				);
			}
		}

		if(isset($backURL)) {
			$fields->push(new HiddenField('BackURL', 'BackURL', $backURL));
		}

		parent::__construct($controller, $name, $fields, $actions);
	}


	/**
	 * Get message from session
	 */
	protected function getMessageFromSession() {
		parent::getMessageFromSession();
		if(($member = Member::currentUser()) &&
				!Session::get('MemberLoginForm.force_message')) {
			$this->message = sprintf(_t('Member.LOGGEDINAS', "You're logged in as %s."), $member->FirstName);
		}
		Session::set('MemberLoginForm.force_message', false);
	}


	/**
	 * Login form handler method
	 *
	 * This method is called when the user clicks on "Log in"
	 *
	 * @param array $data Submitted data
	 */
	public function dologin($data) {
		if($this->performLogin($data)) {
			Session::clear('SessionForms.MemberLoginForm.Email');
			Session::clear('SessionForms.MemberLoginForm.Remember');
			
			if(Member::currentUser()->isPasswordExpired()) {
				if(isset($_REQUEST['BackURL']) && $backURL = $_REQUEST['BackURL']) {
					Session::set('BackURL', $backURL);
				}

				$cp = new ChangePasswordForm($this->controller, 'ChangePasswordForm');
				$cp->sessionMessage('Your password has expired.  Please choose a new one.', 'good');
				
				Director::redirect('Security/changepassword');
				
				
			} else if(isset($_REQUEST['BackURL']) && $backURL = $_REQUEST['BackURL']) {
				Session::clear("BackURL");
				Director::redirect($backURL);
			} else {
				Director::redirectBack();
			}
		} else {
			Session::set('SessionForms.MemberLoginForm.Email', $data['Email']);
			Session::set('SessionForms.MemberLoginForm.Remember', isset($data['Remember']));
			
			if(isset($_REQUEST['BackURL']) && $backURL = $_REQUEST['BackURL']) {
				Session::set('BackURL', $backURL);
			}
			
			if($badLoginURL = Session::get("BadLoginURL")) {
				Director::redirect($badLoginURL);
			} else {
				// Show the right tab on failed login
				Director::redirect(Director::absoluteURL(Security::Link("login")) .
													 '#' . $this->FormName() .'_tab');
			}
		}
	}


	/**
	 * Log out form handler method
	 *
	 * This method is called when the user clicks on "logout" on the form
	 * created when the parameter <i>$checkCurrentUser</i> of the
	 * {@link __construct constructor} was set to TRUE and the user was
	 * currently logged in.
	 */
	public function logout() {
		$s = new Security();
		$s->logout();
	}


  /**
   * Try to authenticate the user
   *
   * @param array Submitted data
   * @return Member Returns the member object on successful authentication
   *                or NULL on failure.
   */
	public function performLogin($data) {
		if($member = MemberAuthenticator::authenticate($data, $this)) {
			$firstname = Convert::raw2xml($member->FirstName);
			Session::set("Security.Message.message", 
				sprintf(_t('Member.WELCOMEBACK', "Welcome Back, %s"), $firstname)
			);
			Session::set("Security.Message.type", "good");

			$member->LogIn(isset($data['Remember']));
			return $member;

		} else {
			$this->extend('authenticationFailed', $data);
			return null;
		}
	}


	/**
	 * Forgot password form handler method
	 *
	 * This method is called when the user clicks on "I've lost my password"
	 *
	 * @param array $data Submitted data
	 */
	function forgotPassword($data) {
		$SQL_data = Convert::raw2sql($data);

		if(($data['Email']) && ($member = DataObject::get_one("Member",
				"Member.Email = '$SQL_data[Email]'"))) {

			$member->generateAutologinHash();

			$member->sendInfo('forgotPassword', array('PasswordResetLink' =>
				Security::getPasswordResetLink($member->AutoLoginHash)));

			Director::redirect('Security/passwordsent/' . urlencode($data['Email']));

		} else if($data['Email']) {
			$this->sessionMessage(
				_t('Member.ERRORSIGNUP', "Sorry, but I don't recognise the e-mail address. Maybe you need " .
					"to sign up, or perhaps you used another e-mail address?"),
				"bad");
			Director::redirectBack();

		} else {
			Director::redirect("Security/lostpassword");
		}
	}

}


?>
