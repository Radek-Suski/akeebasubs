<?php
/**
 * @package   AkeebaSubs
 * @copyright Copyright (c)2010-2014 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/euvatinfo.php';

class AkeebasubsModelSubscribes extends F0FModel
{
	/**
	 * Raw HTML source of the payment form, as returned by the payment plugin
	 *
	 * @var string
	 */
	private $paymentForm = '';

	/**
	 * File handle
	 *
	 * @var resource
	 */
	protected $_urand;

	/**
	 * @var int|null Coupon ID used in the price calculation
	 */
	protected $_coupon_id = null;

	/**
	 * @var int|null Upgrade ID used in the price calculation
	 */
	protected $_upgrade_id = null;

	/**
	 * We cache the results of all time-consuming operations, e.g. vat validation, subscription membership calculation,
	 * tax calculations, etc into this array, saved in the user's session.
	 *
	 * @var array
	 */
	private $_cache = array();

	public function __construct($config = array())
	{
		parent::__construct($config);

		$session = JFactory::getSession();
		$encodedCacheData = $session->get('validation_cache_data', null, 'com_akeebasubs');
		if (!is_null($encodedCacheData))
		{
			$this->_cache = json_decode($encodedCacheData, true);
		}
		else
		{
			$this->_cache = array();
		}

		// Load the state from cache, GET or POST variables
		if (!array_key_exists('state', $this->_cache))
		{
			$this->_cache['state'] = array(
				'paymentmethod' => '',
				'username'      => '',
				'password'      => '',
				'password2'     => '',
				'name'          => '',
				'email'         => '',
				'email2'        => '',
				'address1'      => '',
				'address2'      => '',
				'country'       => 'XX',
				'state'         => '',
				'city'          => '',
				'zip'           => '',
				'isbusiness'    => '',
				'businessname'  => '',
				'vatnumber'     => '',
				'coupon'        => '',
				'occupation'    => '',
				'custom'        => array(),
				'subcustom'     => array(),
			);
		}

		// Otherwise we always see the same level over and over again
		if (array_key_exists('id', $this->_cache['state']))
		{
			unset($this->_cache['state']['id']);
		}

		$rawDataCache = $this->_cache['state'];
		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$rawData = array_merge($rawDataCache, $rawDataGet, $rawDataPost);
		if (!empty($rawData))
		{
			foreach ($rawData as $k => $v)
			{
				if (substr($k, 0, 1) == chr(0))
				{
					// Some people reported a key starting with a null byte causing a fatal error. Uh?!
					continue;
				}

				if (empty($k))
				{
					// Some people reported an empty(!!!) key causing a fatal error. WTF?!
					continue;
				}

				$this->setState($k, $v);
			}
		}

		// Save the new state data in the cache
		$this->_cache['state'] = (array)($this->getState());
		$encodedCacheData = json_encode($this->_cache);
		$session->set('validation_cache_data', $encodedCacheData, 'com_akeebasubs');
	}

	private function getStateVariables($force = false)
	{
		static $stateVars = null;

		if (is_null($stateVars) || $force)
		{

			$session = JFactory::getSession();
			$firstRun = $session->get('firstrun', true, 'com_akeebasubs');

			if ($firstRun)
			{
				$session->set('firstrun', false, 'com_akeebasubs');
			}

			require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/cparams.php';
			$emailasusername = AkeebasubsHelperCparams::getParam('emailasusername', 0);

			$stateVars = (object)array(
				'firstrun'      => $firstRun,
				'slug'          => $this->getState('slug', '', 'string'),
				'id'            => $this->getState('id', 0, 'int'),
				'paymentmethod' => $this->getState('paymentmethod', 'none', 'cmd'),
				'processorkey'  => $this->getState('processorkey', '', 'raw'),
				'username'      => $this->getState('username', '', 'string'),
				'password'      => $this->getState('password', '', 'raw'),
				'password2'     => $this->getState('password2', '', 'raw'),
				'name'          => $this->getState('name', '', 'string'),
				'email'         => $this->getState('email', '', 'string'),
				'email2'        => $this->getState('email2', '', 'string'),
				'address1'      => $this->getState('address1', '', 'string'),
				'address2'      => $this->getState('address2', '', 'string'),
				'country'       => $this->getState('country', '', 'cmd'),
				'state'         => $this->getState('state', '', 'cmd'),
				'city'          => $this->getState('city', '', 'string'),
				'zip'           => $this->getState('zip', '', 'string'),
				'isbusiness'    => $this->getState('isbusiness', '', 'int'),
				'businessname'  => $this->getState('businessname', '', 'string'),
				'occupation'    => $this->getState('occupation', '', 'string'),
				'vatnumber'     => $this->getState('vatnumber', '', 'cmd'),
				'coupon'        => $this->getState('coupon', '', 'string'),
				'custom'        => $this->getState('custom', '', 'raw'),
				'subcustom'     => $this->getState('subcustom', '', 'raw'),
				'opt'           => $this->getState('opt', '', 'cmd')
			);

			if ($emailasusername && (JFactory::getUser()->guest))
			{
				$stateVars->username = $stateVars->email;
			}
		}

		return $stateVars;
	}

	/**
	 * Performs a validation
	 */
	public function getValidation()
	{
		$response = new stdClass();

		$state = $this->getStateVariables();

		if ($state->slug && empty($state->id))
		{
			$list = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->slug($state->slug)
				->getItemList();
			if (!empty($list))
			{
				$item = array_pop($list);
				$state->id = $item->akeebasubs_level_id;
			}
			else
			{
				$state->id = 0;
			}
		}

		switch ($state->opt)
		{
			case 'username':
				$response->validation = $this->_validateUsername();
				break;

			// Perform validations on plugins only
			case 'plugins':
				$this->pluginValidation($response, $state);
				break;

			default:
				$response->validation = $this->_validateState();
				$response->validation->username = $this->_validateUsername()->username;
				$response->validation->password = $this->_validateUsername()->password;
				$response->price = $this->validatePrice();

				$this->pluginValidation($response, $state);

				break;
		}

		return $response;
	}

	private function pluginValidation(&$response, &$state)
	{
		JLoader::import('joomla.plugin.helper');
		JPluginHelper::importPlugin('akeebasubs');

		$app = JFactory::getApplication();

		// Get the results from the custom validation
		$response->custom_validation = array();
		$response->custom_valid = true;

		$jResponse = $app->triggerEvent('onValidate', array($state));
		if (is_array($jResponse) && !empty($jResponse))
		{
			foreach ($jResponse as $pluginResponse)
			{
				if (!is_array($pluginResponse))
				{
					continue;
				}
				if (!array_key_exists('valid', $pluginResponse))
				{
					continue;
				}
				if (!array_key_exists('custom_validation', $pluginResponse))
				{
					continue;
				}

				$response->custom_valid = $response->custom_valid && $pluginResponse['valid'];
				$response->custom_validation = array_merge($response->custom_validation, $pluginResponse['custom_validation']);
				if (array_key_exists('data', $pluginResponse))
				{
					$state = $pluginResponse['data'];
				}
			}
		}

		// Get the results from the per-subscription custom validation
		$response->subscription_custom_validation = array();
		$response->subscription_custom_valid = true;

		$jResponse = $app->triggerEvent('onValidatePerSubscription', array($state));
		if (is_array($jResponse) && !empty($jResponse))
		{
			foreach ($jResponse as $pluginResponse)
			{
				if (!is_array($pluginResponse))
				{
					continue;
				}
				if (!array_key_exists('valid', $pluginResponse))
				{
					continue;
				}
				if (!array_key_exists('subscription_custom_validation', $pluginResponse))
				{
					continue;
				}

				$response->subscription_custom_valid = $response->subscription_custom_valid && $pluginResponse['valid'];
				$response->subscription_custom_validation = array_merge($response->subscription_custom_validation, $pluginResponse['subscription_custom_validation']);
				if (array_key_exists('data', $pluginResponse))
				{
					$state = $pluginResponse['data'];
				}
			}
		}
	}

	/**
	 * Validates the username for uniqueness
	 */
	private function _validateUsername()
	{
		$state = $this->getStateVariables();

		$ret = (object)array('username' => false, 'password' => true);
		$username = $state->username;
		$myUser = JFactory::getUser();

		if (empty($username))
		{
			if (!$myUser->guest)
			{
				$ret->username = true;
			}

			return $ret;
		}

		$user = F0FModel::getTmpInstance('Jusers', 'AkeebasubsModel')
			->username($username)
			->getFirstItem();

		if ($myUser->guest)
		{
			if (empty($state->password) || empty($state->password2))
			{
				$ret->password = false;
			}
			elseif ($state->password != $state->password2)
			{
				$ret->password = false;
			}

			if (empty($user->username))
			{
				$ret->username = true;
			}
			else
			{
				// If it's a blocked user, we should allow reusing the username;
				// this would be a user who tried to subscribe, closed the payment
				// window and came back to re-register. However, if the validation
				// field is non-empty, this is a manually blocked user and should
				// not be allowed to subscribe again.
				if ($user->block)
				{
					if (!empty($user->activation))
					{
						$ret->username = true;
					}
					else
					{
						$ret->username = false;
					}
				}
				else
				{
					$ret->username = false;
				}
			}
		}
		else
		{
			$ret->username = ($user->username == $myUser->username);
		}

		return $ret;
	}

	/**
	 * Validates the state data for completeness
	 */
	private function _validateState()
	{
		$state = $this->getStateVariables();

		if ($state->slug && empty($state->id))
		{
			$list = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->slug($state->slug)
				->getItemList();
			if (!empty($list))
			{
				$item = array_pop($list);
				$state->id = $item->akeebasubs_level_id;
			}
			else
			{
				$state->id = 0;
			}
		}

		require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/cparams.php';
		$personalInfo = AkeebasubsHelperCparams::getParam('personalinfo', 1);
		$allowNonEUVAT = AkeebasubsHelperCparams::getParam('noneuvat', 0);
		$requireCoupon = AkeebasubsHelperCparams::getParam('reqcoupon', 0) ? true : false;

		// 1. Basic checks
		$ret = array(
			'name'         => !empty($state->name),
			'email'        => !empty($state->email),
			'email2'       => !empty($state->email2) && ($state->email == $state->email2),
			'address1'     => $personalInfo == 1 ? !empty($state->address1) : true,
			'country'      => $personalInfo != 0 ? !empty($state->country) : true,
			'state'        => $personalInfo == 1 ? !empty($state->state) : true,
			'city'         => $personalInfo == 1 ? !empty($state->city) : true,
			'zip'          => $personalInfo == 1 ? !empty($state->zip) : true,
			'businessname' => $personalInfo == 1 ? !empty($state->businessname) : true,
			'vatnumber'    => $personalInfo == 1 ? !empty($state->vatnumber) : true,
			'coupon'       => $personalInfo == 1 ? !empty($state->coupon) : true,
		);

		$ret['rawDataForDebug'] = (array)$state;

		// Name validation; must contain AT LEAST two parts (name/surname)
		// separated by a space
		/*
		if(!empty($state->name)) {
			$name = trim($state->name);
			$nameParts = explode(" ", $name);
			if(count($nameParts) < 2) $ret['name'] = false;
		}
		*/

		// Email validation
		if (!empty($state->email))
		{
			$list = F0FModel::getTmpInstance('Jusers', 'AkeebasubsModel')
				->email($state->email)
				->getItemList();
			$validEmail = true;
			foreach ($list as $item)
			{
				if ($item->email == $state->email)
				{
					if ($item->id != JFactory::getUser()->id)
					{
						if (!$item->block)
						{
							// Email belongs to a non-blocked user; this is not allowed.
							$validEmail = false;
							break;
						}
						else
						{
							// Email belongs to a blocked user. Allow reusing it,
							// if the user is not activated yet. The idea is that
							// a newly created user is blocked and has the activation
							// field filled in. This is a user who failed to complete
							// his subscription. If the validation field is empty, it
							// is a user blocked by the administrator who should not
							// be able to subscribe again!
							if (empty($item->activation))
							{
								$validEmail = false;
								break;
							}
						}
					}
				}
			}

			// Double check that it's a valid email
			if ($validEmail)
			{
				$validEmail = $this->validEmail($state->email);
			}

			$ret['email'] = $validEmail;
		}
		else
		{
			$ret['email'] = false;
		}

		// 2. Country validation
		if ($ret['country'] && ($personalInfo != 0))
		{
			require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/select.php';
			$ret['country'] = array_key_exists($state->country, AkeebasubsHelperSelect::$countries) && !empty($state->country);
		}
		elseif ($personalInfo == 0)
		{
			$ret['country'] = 0;
		}
		else
		{
			$ret['country'] = !empty($state->country);
		}

		// 3. State validation
		if ($personalInfo <= 0)
		{
			$ret['state'] = true;
		}
		else
		{
			if (in_array($state->country, array('US', 'CA')))
			{
				require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/select.php';
				$ret['state'] = false;
				foreach (AkeebasubsHelperSelect::$states as $country => $states)
				{
					if (array_key_exists($state->state, $states))
					{
						$ret['state'] = true;
					}
				}
			}
			else
			{
				$ret['state'] = true;
			}
		}

		// 4. Business validation
		// Fix the VAT number's format
		$vat_check = AkeebasubsHelperEuVATInfo::checkVATFormat($state->country, $state->vatnumber);

		if ($vat_check->valid)
		{
			$state->vatnumber = $vat_check->vatnumber;
		}
		else
		{
			$state->vatnumber = '';
		}
		$this->setState('vatnumber', $state->vatnumber);

		if (!$state->isbusiness || ($personalInfo <= 0))
		{
			$ret['businessname'] = true;
			$ret['vatnumber'] = false;
		}
		else
		{
			// Do I have to check the VAT number?
			if (AkeebasubsHelperEuVATInfo::isEUVATCountry($state->country))
			{
				// If the country has two rules with VIES enabled/disabled and a non-zero VAT,
				// we will skip VIES validation. We'll also skip validation if there are no
				// rules for this country (the default tax rate will be applied)

				// First try loading the rules for this level
				$taxrules = F0FModel::getTmpInstance('Taxrules', 'AkeebasubsModel')
					->savestate(0)
					->enabled(1)
					->akeebasubs_level_id($state->id)
					->country($state->country)
					->filter_order('ordering')
					->filter_order_Dir('ASC')
					->limit(0)
					->limitstart(0)
					->getList();

				// If this level has no rules try the "All levels" rules
				if (empty($taxrules))
				{
					$taxrules = F0FModel::getTmpInstance('Taxrules', 'AkeebasubsModel')
						->savestate(0)
						->enabled(1)
						->akeebasubs_level_id(0)
						->country($state->country)
						->filter_order('ordering')
						->filter_order_Dir('ASC')
						->limit(0)
						->limitstart(0)
						->getList();
				}

				$catchRules = 0;
				$lastVies = null;
				if (!empty($taxrules))
				{
					foreach ($taxrules as $rule)
					{
						if (empty($rule->state) && empty($rule->city) && $rule->taxrate && ($lastVies != $rule->vies))
						{
							$catchRules++;
							$lastVies = $rule->vies;
						}
					}
				}
				$mustCheck = ($catchRules < 2) && ($catchRules > 0);

				if ($mustCheck)
				{
					// Can I use cached result? In order to do so...
					$useCachedResult = false;
					// ...I have to be logged in...
					if (!JFactory::getUser()->guest)
					{
						// ...and I must have my viesregistered flag set to 2
						// and my VAT number must match the saved record.
						$userparams = F0FModel::getTmpInstance('Users', 'AkeebasubsModel')
							->user_id(JFactory::getUser()->id)
							->getMergedData(JFactory::getUser()->id);
						if (($userparams->viesregistered == 2) && ($userparams->vatnumber == $state->vatnumber))
						{
							$useCachedResult = true;
						}
					}

					if ($useCachedResult)
					{
						// Use the cached VIES validation result
						$ret['vatnumber'] = true;
						//$ret['onlineviescheck'] = 0;
					}
					else
					{
						// No, check the VAT number against the VIES web service
						$ret['vatnumber'] = AkeebasubsHelperEuVATInfo::isVIESValidVATNumber($state->country, $state->vatnumber);
						//$ret['onlineviescheck'] = 1;
					}

					$ret['novatrequired'] = false;
				}
				else
				{
					$ret['novatrequired'] = true;
				}
			}
			elseif ($allowNonEUVAT)
			{
				// Allow non-EU VAT input
				$ret['novatrequired'] = true;
				$ret['vatnumber'] = AkeebasubsHelperEuVATInfo::isVIESValidVATNumber($state->country, $state->vatnumber);
			}
		}

		// 5. Coupon validation
		$ret['coupon'] = $this->_validateCoupon(!$requireCoupon);

		return (object)$ret;
	}

	/**
	 * Calculates the level's price applicable to the specific user and the
	 * actual state information
	 *
	 * @param   bool $force Set true to force recalculation of the price validation
	 *
	 * @return  stdClass
	 */
	public function validatePrice($force = false)
	{
		static $result = null;

		if (is_null($result) || $force)
		{
			$state = $this->getStateVariables($force);

			// Get the subscription level
			$level = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->setId($state->id)
				->getItem();

			// Get the user's subscription levels and calculate the signup fee
			$subIDs = array();
			$signup_fee = 0;
			$user = JFactory::getUser();

			if ($user->id)
			{
				$mysubs = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
					->user_id($user->id)
					->paystate('C')
					->getItemList();

				if (!empty($mysubs))
				{
					foreach ($mysubs as $sub)
					{
						$subIDs[] = $sub->akeebasubs_level_id;
					}
				}

				$subIDs = array_unique($subIDs);

				if (!in_array($level->akeebasubs_level_id, $subIDs))
				{
					$signup_fee = $level->signupfee;
				}
			}
			else
			{
				$signup_fee = $level->signupfee;
			}

			// Get the default price value
			$netPrice = (float)$level->price + (float)$signup_fee;

			// Net price modifiers (via plugins)
			$price_modifier = 0;
			JLoader::import('joomla.plugin.helper');
			JPluginHelper::importPlugin('akeebasubs');
			JPluginHelper::importPlugin('akpayment');
			$app = JFactory::getApplication();
			$priceValidationData = array_merge((array)$state, array('level' => $level, 'netprice' => $netPrice));
			$jResponse = $app->triggerEvent('onValidateSubscriptionPrice', array((object)$priceValidationData));

			if (is_array($jResponse) && !empty($jResponse))
			{
				foreach ($jResponse as $pluginResponse)
				{
					if (empty($pluginResponse))
					{
						continue;
					}
					$price_modifier += $pluginResponse;
				}
			}

			$netPrice += $price_modifier;

			// Coupon discount
			$couponDiscount = 0;
			$validCoupon = $this->_validateCoupon(false);

			$couponDiscount = 0;

			if ($validCoupon)
			{
				$coupon = F0FModel::getTmpInstance('Coupons', 'AkeebasubsModel')
					->coupon(strtoupper($state->coupon))
					->getFirstItem();

				$this->_coupon_id = $coupon->akeebasubs_coupon_id;

				switch ($coupon->type)
				{
					case 'value':
						$couponDiscount = (float)$coupon->value;
						if ($couponDiscount > $netPrice)
						{
							$couponDiscount = $netPrice;
						}
						if ($couponDiscount <= 0)
						{
							$couponDiscount = 0;
						}
						break;

					case 'percent':
						$percent = (float)$coupon->value / 100.0;
						if ($percent <= 0)
						{
							$percent = 0;
						}
						if ($percent > 1)
						{
							$percent = 1;
						}
						$couponDiscount = $percent * $netPrice;
						break;
				}
			}
			else
			{
				$this->_coupon_id = null;
			}

			// Upgrades (auto-rule) validation
			$autoDiscount = 0;
			$discountStructure = $this->_getAutoDiscount();
			$autoDiscount = $discountStructure['discount'];

			// Should I use the coupon code or the automatic discount?
			$useCoupon = false;
			$useAuto = false;
			if ($validCoupon)
			{
				if ($autoDiscount > $couponDiscount)
				{
					$discount = $autoDiscount;
					$useAuto = true;
					$this->_coupon_id = null;
				}
				else
				{
					$discount = $couponDiscount;
					$useCoupon = true;
					$this->_upgrade_id = null;
				}
			}
			else
			{
				$this->_coupon_id = null;
				$discount = $autoDiscount;
				$useAuto = true;
			}

			$discount = $useCoupon ? $couponDiscount : $autoDiscount;
			$couponid = is_null($this->_coupon_id) ? 0 : $this->_coupon_id;
			$upgradeid = is_null($this->_upgrade_id) ? 0 : $this->_upgrade_id;

			// If we are not using the automatic discount, make sure the oldsub
			// and expiration fields are reset
			// -- NO! Subscription level relations must not be bound to the
			// discount.
			/**
			 * if (!$useAuto)
			 * {
			 * $discountStructure['oldsub'] = null;
			 * $discountStructure['expiration'] = 'overlap';
			 * }
			 * /**/

			// Get the applicable tax rule
			$taxRule = $this->_getTaxRule();

			// Calculate the base price minimising rounding errors
			$basePrice = 0.01 * (100 * $netPrice - 100 * $discount);
			if ($basePrice < 0.01)
			{
				$basePrice = 0;
			}
			// Calculate the tax amount minimising rounding errors
			$taxAmount = 0.01 * ($taxRule->taxrate * $basePrice);
			// Calculate the gross amount minimising rounding errors
			$grossAmount = 0.01 * (100 * $basePrice + 100 * $taxAmount);

			// Calculate the recurring amount, if necessary
			$recurringAmount = 0;
			if ($level->recurring && (abs($signup_fee) >= 0.01))
			{
				$rectaxAmount = 0.01 * ($taxRule->taxrate * $level->price);
				$recurringAmount = 0.01 * (100 * $level->price + 100 * $rectaxAmount);
			}

			$result = (object)array(
				'net'        => sprintf('%1.02F', round($netPrice, 2)),
				'realnet'    => sprintf('%1.02F', round($level->price, 2)),
				'signup'     => sprintf('%1.02F', round($signup_fee, 2)),
				'discount'   => sprintf('%1.02F', round($discount, 2)),
				'taxrate'    => sprintf('%1.02F', (float)$taxRule->taxrate),
				'tax'        => sprintf('%1.02F', round($taxAmount, 2)),
				'gross'      => sprintf('%1.02F', round($grossAmount, 2)),
				'recurring'  => sprintf('%1.02F', round($recurringAmount, 2)),
				'usecoupon'  => $useCoupon ? 1 : 0,
				'useauto'    => $useAuto ? 1 : 0,
				'couponid'   => $couponid,
				'upgradeid'  => $upgradeid,
				'oldsub'     => $discountStructure['oldsub'],
				'allsubs'    => $discountStructure['allsubs'],
				'expiration' => $discountStructure['expiration'],
			);
		}

		return $result;
	}

	/**
	 * Validates a coupon code, making sure it exists, it's activated, it's not expired,
	 * it applies to the specific subscription and user.
	 */
	private function _validateCoupon($validIfNotExists = true, $force = false)
	{
		static $couponCode = null;
		static $valid = false;
		static $couponid = null;

		if ($force)
		{
			$couponCode = null;
			$valid = false;
			$couponid = null;
		}

		$state = $this->getStateVariables();
		$this->_coupon_id = null;

		if ($state->coupon)
		{
			if ($state->coupon == $couponCode)
			{
				$this->_coupon_id = $valid ? $couponid : null;

				return $valid;
			}
		}

		$valid = $validIfNotExists;
		if ($state->coupon)
		{
			$couponCode = $state->coupon;
			$valid = false;

			$coupon = F0FModel::getTmpInstance('Coupons', 'AkeebasubsModel')
				->coupon(strtoupper(trim($state->coupon)))
				->getFirstItem();
			if (empty($coupon->akeebasubs_coupon_id))
			{
				$coupon = null;
			}

			if (is_object($coupon))
			{
				$valid = false;
				if ($coupon->enabled && (strtoupper($coupon->coupon) == strtoupper($couponCode)))
				{
					// Check validity period
					JLoader::import('joomla.utilities.date');
					$jFrom = new JDate($coupon->publish_up);
					$jTo = new JDate($coupon->publish_down);
					$jNow = new JDate();

					$valid = ($jNow->toUnix() >= $jFrom->toUnix()) && ($jNow->toUnix() <= $jTo->toUnix());

					// Check levels list
					if ($valid && !empty($coupon->subscriptions))
					{
						$levels = explode(',', $coupon->subscriptions);
						$valid = in_array($state->id, $levels);
					}

					// Check user
					if ($valid && $coupon->user)
					{
						$user_id = JFactory::getUser()->id;
						$valid = $user_id == $coupon->user;
					}

					// Check email
					if ($valid && $coupon->email)
					{
						$valid = $state->email == $coupon->email;
					}

					// Check user group levels
					if ($valid && !empty($coupon->usergroups))
					{
						$groups = explode(',', $coupon->usergroups);
						$ugroups = JFactory::getUser()->getAuthorisedGroups();
						$valid = 0;
						foreach ($ugroups as $ugroup)
						{
							if (in_array($ugroup, $groups))
							{
								$valid = 1;
								break;
							}
						}
					}

					// Check hits limit
					if ($valid && $coupon->hitslimit)
					{
						// Get the real coupon hits
						$hits = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
							->savestate(0)
							->coupon_id($coupon->akeebasubs_coupon_id)
							->paystate('C')
							->limit(0)
							->limitstart(0)
							->getTotal();
						if ($coupon->hitslimit >= 0)
						{
							$valid = $hits < $coupon->hitslimit;
							if (($coupon->hits != $hits) || ($hits >= $coupon->hitslimit))
							{
								$coupon->hits = $hits;
								$coupon->enabled = $hits < $coupon->hitslimit;
								$coupon->store();
							}
						}
					}

					// Check user hits limit
					if ($valid && $coupon->userhits && !JFactory::getUser()->guest)
					{
						$user_id = JFactory::getUser()->id;
						// How many subscriptions with a paystate of C,P for this user
						// are using this coupon code?
						$hits = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
							->savestate(0)
							->coupon_id($coupon->akeebasubs_coupon_id)
							->paystate('C,P')
							->user_id($user_id)
							->limit(0)
							->limitstart(0)
							->getTotal();
						$valid = $hits < $coupon->userhits;
					}
				}
				else
				{
					$valid = false;
				}
			}
		}

		$this->_coupon_id = $valid ? $couponid : null;

		return $valid;
	}

	/**
	 * Loads any relevant auto discount (upgrade rules or discount) and returns
	 * the max discount possible under those rules, as well as related
	 * information in subscription expiration and so on.
	 *
	 * @return array Discount type and value
	 */
	private function _getAutoDiscount()
	{
		// Get the automatic discount based on upgrade rules
		$autoDiscount = $this->_getUpgradeRule();

		// Initialise the return value
		$ret = array(
			'discount'   => $autoDiscount, // discount amount
			'expiration' => 'overlap', // old subscription expiration mode
			'allsubs'    => null, // all old subscription ids
			'oldsub'     => null, // old subscription id
		);

		// Check if we have a valid subscription level and user
		if (!JFactory::getUser()->guest)
		{
			$relationData = $this->_getLevelRelation($autoDiscount);

			// Check that a relation row is relevant
			if (!is_null($relationData['relation']))
			{
				// As long as we have an expiration method other than "overlap"
				// pass along the subscriptions which will be replaced / used
				// to extend the subscription time
				if ($relationData['relation']->expiration != 'overlap')
				{
					$ret['expiration'] = $relationData['relation']->expiration;
					$ret['oldsub'] = $relationData['oldsub'];
					$ret['allsubs'] = $relationData['allsubs'];
					$this->_upgrade_id = null;
				}

				// Non-rule-based relation discount
				if ($relationData['relation']->mode != 'rules')
				{
					// Get the discount from the levels relation and make sure it's greater than the rule-based discount
					$relDiscount = $relationData['discount'];

					if ($relDiscount > $autoDiscount)
					{
						// yes, it's greated than the upgrade rule-based discount. Use it.
						$ret['discount'] = $relDiscount;
					}
				}
				// Rule-based relation discount
				else
				{
					$ret['discount'] = $relDiscount;
				}
			}
		}

		// Finally, return the structure
		return $ret;
	}

	/**
	 * Gets the applicable subscription level relation rule applicable for this
	 * subscription attempt.
	 *
	 * @return  array  Hash array. discount is the value of the discount,
	 *                 relation is a copy of the relation row, oldsub is the id
	 *                 of the old subscription on which the relation row was
	 *                 applied against.
	 */
	private function _getLevelRelation($autoDiscount)
	{
		$state = $this->getStateVariables();

		// Get the id from the slug if it's not present
		if ($state->slug && empty($state->id))
		{
			$list = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->slug($state->slug)
				->getItemList();
			if (!empty($list))
			{
				$item = array_pop($list);
				$state->id = $item->akeebasubs_level_id;
			}
			else
			{
				$state->id = 0;
			}
		}

		// Initialise the return array
		$ret = array(
			'discount' => 0,
			'relation' => null,
			'oldsub'   => null,
			'allsubs'  => null,
		);

		$combineret = array(
			'discount' => 0,
			'relation' => null,
			'oldsub'   => null,
			'allsubs'  => array(),
		);

		// Get applicable relation rules
		$autoRules = F0FModel::getTmpInstance('Relations', 'AkeebasubsModel')
			->savestate(0)
			->target_level_id($state->id)
			->enabled(1)
			->limit(0)
			->limitstart(0)
			->getItemList();

		if (empty($autoRules))
		{
			// No point continuing if we don't have any rules, right?
			return $ret;
		}

		// Get the current subscription level's net worth
		$level = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
			->setId($state->id)
			->getItem();
		$net = (float)$level->price;

		foreach ($autoRules as $rule)
		{
			// Get all of the user's paid subscriptions with an expiration date
			// in the future in the source_level_id of the rule.
			$jNow = new JDate();
			$user_id = JFactory::getUser()->id;
			$subscriptions = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
				->savestate(0)
				->level($rule->source_level_id)
				->user_id($user_id)
				->expires_from($jNow->toSql())
				->paystate('C')
				->filter_order('publish_down')
				->filter_order('ASC')
				->getItemList(true);

			if (empty($subscriptions))
			{
				// If there are no subscriptions on this level don't bother.
				continue;
			}

			$allsubs = array();
			foreach ($subscriptions as $sub)
			{
				$allsubs[] = $sub->akeebasubs_level_id;
			}
			reset($allsubs);

			switch ($rule->mode)
			{
				// Rules-based discount.
				case 'rules':
					$discount = $autoDiscount;
					break;

				// Fixed discount
				case 'fixed':
					if ($rule->type == 'value')
					{
						$discount = (float)$rule->amount;
					}
					else
					{
						$discount = $net * (float)$rule->amount / 100;
					}
					break;

				// Flexible subscriptions
				case 'flexi':
					// Translate period to days
					switch ($rule->flex_uom)
					{
						case 'd':
							$modifier = 1;
							break;

						case 'w':
							$modifier = 7;
							break;

						case 'm':
							$modifier = 30;
							break;

						case 'y':
							$modifier = 365;
							break;
					}
					$modifier = $modifier * 86400; // translate to seconds

					$period = $rule->flex_period;

					// Calculate presence
					$remaining_seconds = 0;
					$jNow = new JDate();
					$now = $jNow->toUnix();
					foreach ($subscriptions as $sub)
					{
						if ($rule->flex_timecalculation && !$sub->enabled)
						{
							continue;
						}

						$jFrom = new JDate($sub->publish_up);
						$jTo = new JDate($sub->publish_down);
						$from = $jFrom->toUnix();
						$to = $jTo->toUnix();
						if ($from > $now)
						{
							$remaining_seconds += $to - $from;
						}
						else
						{
							$remaining_seconds += $to - $now;
						}
					}
					$remaining = $remaining_seconds / $modifier;

					// Check for low threshold
					if (($rule->low_threshold > 0) && ($remaining <= $rule->low_threshold))
					{
						$discount = $rule->low_amount;
					}
					// Check for high threshold
					elseif (($rule->high_threshold > 0) && ($remaining >= $rule->high_threshold))
					{
						$discount = $rule->high_amount;
					}
					else
						// Calculate discount based on presence
					{
						// Round the quantised presence
						switch ($rule->time_rounding)
						{
							case 'floor':
								$remaining = floor($remaining / $period);
								break;

							case 'ceil':
								$remaining = ceil($remaining / $period);
								break;

							case 'round':
								$remaining = round($remaining / $period);
								break;
						}
						$discount = $rule->flex_amount * $remaining;
					}

					// Translate percentages to net values
					if ($rule->type == 'percent')
					{
						$discount = $net * (float)$discount / 100;
					}

					break;
			}

			// Combined rule. Add to, um, the combined rules return array
			if ($rule->combine)
			{
				$combineret['discount'] += $discount;
				$combineret['relation'] = clone $rule;
				$combineret['allsubs'] = array_merge($combineret['allsubs'], $allsubs);
				$combineret['allsubs'] = array_unique($combineret['allsubs']);
				foreach ($subscriptions as $sub)
				{
					// Loop until we find an enabled subscription
					if (!$sub->enabled)
					{
						continue;
					}
					// Use that subscription and beat it
					$combineret['oldsub'] = $sub->akeebasubs_subscription_id;
					break;
				}
			}
			elseif ($discount > $ret['discount'])
				// If the current discount is greater than what we already have, use it
			{
				$ret['discount'] = $discount;
				$ret['relation'] = clone $rule;
				$ret['allsubs'] = $allsubs;
				foreach ($subscriptions as $sub)
				{
					// Loop until we find an enabled subscription
					if (!$sub->enabled)
					{
						continue;
					}
					// Use that subscription and beat it
					$ret['oldsub'] = $sub->akeebasubs_subscription_id;
					break;
				}
			}
		}

		// Finally, check if the combined rule trumps the currently selected
		// rule. If it does, use it instead of the regular return array.
		if (
			($combineret['discount'] > $ret['discount']) ||
			(($ret['discount'] <= 0.01) && !is_null($combineret['relation']))
		)
		{
			$ret = $combineret;
		}

		return $ret;
	}

	/**
	 * Loads any relevant upgrade rules and returns the max discount possible
	 * under those rules.
	 *
	 * @return float Discount amount
	 */
	private function _getUpgradeRule()
	{
		$state = $this->getStateVariables();

		// Get the id from the slug if it's not present
		if ($state->slug && empty($state->id))
		{
			$list = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->slug($state->slug)
				->getItemList();
			if (!empty($list))
			{
				$item = array_pop($list);
				$state->id = $item->akeebasubs_level_id;
			}
			else
			{
				$state->id = 0;
			}
		}

		// Check that we do have a user (if there's no logged in user, we have
		// no subscription information, ergo upgrades are not applicable!)
		$user_id = JFactory::getUser()->id;
		if (empty($user_id))
		{
			$this->_upgrade_id = null;

			return 0;
		}

		// Get applicable auto-rules
		$autoRules = F0FModel::getTmpInstance('Upgrades', 'AkeebasubsModel')
			->savestate(0)
			->to_id($state->id)
			->enabled(1)
			->limit(0)
			->limitstart(0)
			->getItemList();

		if (empty($autoRules))
		{
			$this->_upgrade_id = null;

			return 0;
		}

		// Get the user's list of subscriptions
		$subscriptions = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
			->savestate(0)
			->user_id($user_id)
			->enabled(1)
			->limit(0)
			->limitstart(0)
			->getList();

		if (empty($subscriptions))
		{
			$this->_upgrade_id = null;

			return 0;
		}

		$subs = array();
		JLoader::import('joomla.utilities.date');
		$jNow = new JDate();
		$uNow = $jNow->toUnix();

		$subPayments = array();

		foreach ($subscriptions as $subscription)
		{
			$jFrom = new JDate($subscription->publish_up);
			$uFrom = $jFrom->toUnix();
			$presence = $uNow - $uFrom;
			$subs[$subscription->akeebasubs_level_id] = $presence;

			$jOn = new JDate($subscription->created_on);
			if (!array_key_exists($subscription->akeebasubs_level_id, $subPayments))
			{
				$subPayments[$subscription->akeebasubs_level_id] = array(
					'value' => $subscription->net_amount,
					'on'    => $jOn->toUnix(),
				);
			}
			else
			{
				$oldOn = $subPayments[$subscription->akeebasubs_level_id]['on'];
				if ($oldOn < $jOn->toUnix())
				{
					$subPayments[$subscription->akeebasubs_level_id] = array(
						'value' => $subscription->net_amount,
						'on'    => $jOn->toUnix(),
					);
				}
			}
		}

		// Get the current subscription level's net worth
		$level = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
			->setId($state->id)
			->getItem();
		$net = (float)$level->price;

		if ($net == 0)
		{
			$this->_upgrade_id = null;

			return 0;
		}

		$discount = 0;
		$this->_upgrade_id = null;


		// Remove any rules that do not apply
		foreach ($autoRules as $i => $rule)
		{
			if (
				// Make sure there is an active subscription in the From level
				!(array_key_exists($rule->from_id, $subs))
				// Make sure the min/max presence is repected
				|| ($subs[$rule->from_id] < ($rule->min_presence * 86400))
				|| ($subs[$rule->from_id] > ($rule->max_presence * 86400))
				// If From and To levels are different, make sure there is no active subscription in the To level yet
				|| ($rule->to_id != $rule->from_id && array_key_exists($rule->to_id, $subs))
			)
			{
				unset($autoRules[$i]);
			}
		}

		// First add add all combined rules
		foreach ($autoRules as $i => $rule)
		{
			if (!$rule->combine)
			{
				continue;
			}

			switch ($rule->type)
			{
				case 'value':
					$discount += $rule->value;
					$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					break;

				case 'percent':
					$newDiscount = $net * (float)$rule->value / 100.00;
					$discount += $newDiscount;
					$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					break;

				case 'lastpercent':
					if (!array_key_exists($rule->from_id, $subPayments))
					{
						$lastNet = 0.00;
					}
					else
					{
						$lastNet = $subPayments[$rule->from_id]['value'];
					}
					$newDiscount = (float)$lastNet * (float)$rule->value / 100.00;
					$discount += $newDiscount;
					$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					break;
			}
			unset($autoRules[$i]);
		}

		// Then check all non-combined rules if they give a higher discount
		foreach ($autoRules as $rule)
		{
			if ($rule->combine)
			{
				continue;
			}

			switch ($rule->type)
			{
				case 'value':
					if ($rule->value > $discount)
					{
						$discount = $rule->value;
						$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					}
					break;

				case 'percent':
					$newDiscount = $net * (float)$rule->value / 100.00;
					if ($newDiscount > $discount)
					{
						$discount = $newDiscount;
						$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					}
					break;

				case 'lastpercent':
					if (!array_key_exists($rule->from_id, $subPayments))
					{
						$lastNet = 0.00;
					}
					else
					{
						$lastNet = $subPayments[$rule->from_id]['value'];
					}
					$newDiscount = (float)$lastNet * (float)$rule->value / 100.00;
					if ($newDiscount > $discount)
					{
						$discount = $newDiscount;
						$this->_upgrade_id = $rule->akeebasubs_upgrade_id;
					}
					break;
			}
		}

		return $discount;
	}

	/**
	 * Gets the applicable tax rule based on the state variables
	 */
	private function _getTaxRule()
	{
		// Do we have a VIES registered VAT number?
		$validation = $this->_validateState();
		$state = $this->getStateVariables();
		$isVIES = $validation->vatnumber && AkeebasubsHelperEuVATInfo::isEUVATCountry($state->country);

		if ($state->slug && empty($state->id))
		{
			$list = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->slug($state->slug)
				->getItemList();
			if (!empty($list))
			{
				$item = array_pop($list);
				$state->id = $item->akeebasubs_level_id;
			}
			else
			{
				$state->id = 0;
			}
		}

		// First try loading the rules for this level
		$taxrules = F0FModel::getTmpInstance('Taxrules', 'AkeebasubsModel')
			->savestate(0)
			->enabled(1)
			->akeebasubs_level_id($state->id)
			->filter_order('ordering')
			->filter_order_Dir('ASC')
			->limit(0)
			->limitstart(0)
			->getItemList();

		// If this level has no rules try the "All levels" rules
		if (empty($taxrules))
		{
			$taxrules = F0FModel::getTmpInstance('Taxrules', 'AkeebasubsModel')
				->savestate(0)
				->enabled(1)
				->akeebasubs_level_id(0)
				->filter_order('ordering')
				->filter_order_Dir('ASC')
				->limit(0)
				->limitstart(0)
				->getItemList();
		}

		$bestTaxRule = (object)array(
			'match'   => 0,
			'fuzzy'   => 0,
			'taxrate' => 0
		);

		foreach ($taxrules as $rule)
		{
			// For each rule, get the match and fuzziness rating. The best, least fuzzy and last match wins.
			$match = 0;
			$fuzzy = 0;

			if (empty($rule->country))
			{
				$match++;
				$fuzzy++;
			}
			elseif ($rule->country == $state->country)
			{
				$match++;
			}

			if (empty($rule->state))
			{
				$match++;
				$fuzzy++;
			}
			elseif ($rule->state == $state->state)
			{
				$match++;
			}

			if (empty($rule->city))
			{
				$match++;
				$fuzzy++;
			}
			elseif (strtolower(trim($rule->city)) == strtolower(trim($state->city)))
			{
				$match++;
			}

			if (($rule->vies && $isVIES) || (!$rule->vies && !$isVIES))
			{
				$match++;
			}

			if (
				($bestTaxRule->match < $match) ||
				(($bestTaxRule->match == $match) && ($bestTaxRule->fuzzy > $fuzzy))
			)
			{
				if ($match == 0)
				{
					continue;
				}
				$bestTaxRule->match = $match;
				$bestTaxRule->fuzzy = $fuzzy;
				$bestTaxRule->taxrate = $rule->taxrate;
				$bestTaxRule->id = $rule->akeebasubs_taxrule_id;
			}
		}

		return $bestTaxRule;
	}

	/**
	 * Gets a list of payment plugins and their titles
	 */
	public function getPaymentPlugins()
	{
		JLoader::import('joomla.plugin.helper');
		JPluginHelper::importPlugin('akpayment');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentGetIdentity');

		$ret = array();

		foreach ($jResponse as $item)
		{
			if (is_object($item))
			{
				$ret[] = $item;
			}
			elseif (is_array($item))
			{
				if (array_key_exists('name', $item))
				{
					$ret[] = (object)$item;
				}
				else
				{
					foreach ($item as $anItem)
					{
						if (is_object($anItem))
						{
							$ret[] = $anItem;
						}
						else
						{
							$ret[] = (object)$anItem;
						}
					}
				}
			}
		}

		return $ret; // name, title
	}

	/**
	 * Checks that the current state passes the validation
	 *
	 * @return bool
	 */
	public function isValid()
	{
		// Step #1. Check the validity of the user supplied information
		// ----------------------------------------------------------------------
		$validation = $this->getValidation();
		$state = $this->getStateVariables();

		require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/cparams.php';
		$requireCoupon = AkeebasubsHelperCparams::getParam('reqcoupon', 0) ? true : false;

		// Iterate the core validation rules
		$isValid = true;
		foreach ($validation->validation as $key => $validData)
		{
			require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/cparams.php';
			if (AkeebasubsHelperCparams::getParam('personalinfo', 1) == 0)
			{
				if (!in_array($key, array('username', 'email', 'email2', 'name', 'coupon')))
				{
					continue;
				}
			}
			elseif (AkeebasubsHelperCparams::getParam('personalinfo', 1) == -1)
			{
				if (!in_array($key, array('username', 'email', 'email2', 'name', 'country', 'coupon')))
				{
					continue;
				}
			}
			// An invalid (not VIES registered) VAT number is not a fatal error
			if ($key == 'vatnumber')
			{
				continue;
			}
			// A wrong coupon code is not a fatal error, unless we require a coupon code
			if (!$requireCoupon && ($key == 'coupon'))
			{
				continue;
			}
			// A missing business occupation is not a fatal error either
			if ($key == 'occupation')
			{
				continue;
			}
			// This is a dummy key which must be ignored
			if ($key == 'novatrequired')
			{
				continue;
			}

			$isValid = $isValid && $validData;
			if (!$isValid)
			{
				if ($key == 'username')
				{
					$user = JFactory::getUser();
					if ($user->username == $state->username)
					{
						$isValid = true;
					}
					else
					{
						break;
					}
				}
				break;
			}
		}
		// Make sure custom fields also validate
		$isValid = $isValid && $validation->custom_valid && $validation->subscription_custom_valid;

		return $isValid;
	}

	/**
	 * Updates the user info based on the state data
	 *
	 * @param bool $allowNewUser When true, we can create a new user. False, only update an existing user's data.
	 *
	 * @return boolean
	 */
	public function updateUserInfo($allowNewUser = true, $level = null)
	{
		$state = $this->getStateVariables();
		$user = JFactory::getUser();
		$user = $this->getState('user', $user);

		if (($user->id == 0) && !$allowNewUser)
		{
			// New user creation is not allowed. Sorry.
			return false;
		}

		if ($user->id == 0)
		{
			// Check for an existing, blocked, unactivated user with the same
			// username or email address.
			$user1 = F0FModel::getTmpInstance('Jusers', 'AkeebasubsModel')
				->username($state->username)
				->block(1)
				->getFirstItem();
			$user2 = F0FModel::getTmpInstance('Jusers', 'AkeebasubsModel')
				->email($state->email)
				->block(1)
				->getFirstItem();
			$id1 = $user1->id;
			$id2 = $user2->id;
			// Do we have a match?
			if ($id1 || $id2)
			{
				if ($id1 == $id2)
				{
					// Username and email match with the blocked user; reuse that
					// user, please.
					$user = JFactory::getUser($user1->id);
				}
				elseif ($id1 && $id2)
				{
					// We have both the same username and same email, but in two
					// different users. In order to avoid confusion we will remove
					// user 2 and change user 1's email into the email address provided

					// Remove the last subscription for $user2 (it will be an unpaid one)
					$submodel = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel');
					$substodelete = $submodel
						->user_id($id2)
						->getList();
					if (!empty($substodelete))
					{
						foreach ($substodelete as $subtodelete)
						{
							$subtable = $submodel->getTable();
							$subtable->delete($subtodelete->akeebasubs_subscription_id);
						}
					}

					// Remove $user2 and set $user to $user1 so that it gets updated
					$user2->delete($id2);
					$user = JFactory::getUser($user1->id);
					$user->email = $state->email;
					$user->save(true);
				}
				elseif (!$id1 && $id2)
				{
					// We have a user with the same email, but the wrong username.
					// Use this user (the username is updated later on)
					$user = JFactory::getUser($user2->id);
				}
				elseif ($id1 && !$id2)
				{
					// We have a user with the same username, but the wrong email.
					// Use this user (the email is updated later on)
					$user = JFactory::getUser($user1->id);
				}
			}
		}

		if (is_null($user->id) || ($user->id == 0))
		{
			// CREATE A NEW USER
			$params = array(
				'name'      => $state->name,
				'username'  => $state->username,
				'email'     => $state->email,
				'password'  => $state->password,
				'password2' => $state->password2
			);

			$user = new JUser(0);

			JLoader::import('joomla.application.component.helper');
			$usersConfig = JComponentHelper::getParams('com_users');
			$newUsertype = $usersConfig->get('new_usertype');

			// get the New User Group from com_users' settings
			if (empty($newUsertype))
			{
				$newUsertype = 2;
			}
			$params['groups'] = array($newUsertype);

			$params['sendEmail'] = 0;

			// Set the user's default language to whatever the site's current language is
			if (version_compare(JVERSION, '3.0', 'ge'))
			{
				$params['params'] = array(
					'language' => JFactory::getConfig()->get('language')
				);
			}
			else
			{
				$params['params'] = array(
					'language' => JFactory::getConfig()->getValue('config.language')
				);
			}

			// We always block the user, so that only a successful payment or
			// clicking on the email link activates his account. This is to
			// prevent spam registrations when the subscription form is abused.
			JLoader::import('joomla.user.helper');
			$params['block'] = 1;

			$randomString = JUserHelper::genRandomPassword();
			if (version_compare(JVERSION, '3.2', 'ge'))
			{
				$hash = JApplication::getHash($randomString);
			}
			else
			{
				$hash = JFactory::getApplication()->getHash($randomString);
			}
			$params['activation'] = $hash;

			$userIsSaved = false;
			$user->bind($params);
			$userIsSaved = $user->save();
		}
		else
		{
			// UPDATE EXISTING USER

			// Remove unpaid subscriptions on the same level for this user
			$unpaidSubs = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
				->user_id($user->id)
				->paystate('N', 'X')
				->getItemList();
			if (!empty($unpaidSubs))
			{
				foreach ($unpaidSubs as $unpaidSub)
				{
					$table = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')->getTable();
					$table->delete($unpaidSub->akeebasubs_subscription_id);
				}
			}

			// Update existing user's details
			$userRecord = F0FModel::getTmpInstance('Jusers', 'AkeebasubsModel')
				->setId($user->id)
				->getItem();

			$updates = array(
				'name'  => $state->name,
				'email' => $state->email
			);
			if (!empty($state->password) && ($state->password == $state->password2))
			{
				JLoader::import('joomla.user.helper');
				$salt = JUserHelper::genRandomPassword(32);
				$pass = JUserHelper::getCryptedPassword($state->password, $salt);
				$updates['password'] = $pass . ':' . $salt;
			}
			if (!empty($state->username))
			{
				$updates['username'] = $state->username;
			}
			$userIsSaved = $userRecord->save($updates);
		}

		// Send activation email for free subscriptions if confirmfree is enabled
		if ($user->block && ($level->price < 0.01))
		{
			if (!class_exists('AkeebasubsHelperCparams'))
			{
				require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/helpers/cparams.php';
			}
			$confirmfree = AkeebasubsHelperCparams::getParam('confirmfree', 0);
			if ($confirmfree)
			{
				// Send the activation email
				if (!isset($params))
				{
					$params = array();
				}

				$this->sendActivationEmail($user, $params);
			}
		}

		if (!$userIsSaved)
		{
			JError::raiseWarning('', JText::_($user->getError())); // ...raise a Warning
			return false;
		}
		else
		{
			$this->setState('user', $user);
		}

		return $userIsSaved;
	}

	/**
	 * Saves the custom fields of a user record
	 *
	 * @return bool
	 */
	public function saveCustomFields()
	{
		$state = $this->getStateVariables();
		$validation = $this->getValidation();

		$user = JFactory::getUser();
		$user = $this->getState('user', $user);

		// Find an existing record
		$list = F0FModel::getTmpInstance('Users', 'AkeebasubsModel')
			->user_id($user->id)
			->getItemList();

		if (!count($list))
		{
			$id = 0;
		}
		else
		{
			$thisUser = array_pop($list);
			$id = $thisUser->akeebasubs_user_id;
		}

		$data = array(
			'akeebasubs_user_id' => $id,
			'user_id'            => $user->id,
			'isbusiness'         => $state->isbusiness ? 1 : 0,
			'businessname'       => $state->businessname,
			'occupation'         => $state->occupation,
			'vatnumber'          => $state->vatnumber,
			'viesregistered'     => $validation->validation->vatnumber,
			// @todo Ask for tax authority
			'taxauthority'       => '',
			'address1'           => $state->address1,
			'address2'           => $state->address2,
			'city'               => $state->city,
			'state'              => $state->state,
			'zip'                => $state->zip,
			'country'            => $state->country,
			'params'             => $state->custom
		);

		// Allow plugins to post-process the fields
		JLoader::import('joomla.plugin.helper');
		JPluginHelper::importPlugin('akeebasubs');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKSignupUserSave', array((object)$data));
		if (is_array($jResponse) && !empty($jResponse))
		{
			foreach ($jResponse as $pResponse)
			{
				if (!is_array($pResponse))
				{
					continue;
				}
				if (empty($pResponse))
				{
					continue;
				}
				if (array_key_exists('params', $pResponse))
				{
					if (!empty($pResponse['params']))
					{
						foreach ($pResponse['params'] as $k => $v)
						{
							$data['params'][$k] = $v;
						}
					}
					unset($pResponse['params']);
				}
				$data = array_merge($data, $pResponse);
			}
		}

		// Serialize custom fields
		$data['params'] = json_encode($data['params']);

		$status = F0FModel::getTmpInstance('Users', 'AkeebasubsModel')
			->setId($id)
			->getItem()
			->save($data);

		return $status;
	}

	/**
	 * Processes the form data and creates a new subscription
	 */
	public function createNewSubscription()
	{
		// Fetch state and validation variables
		$this->setState('opt', '');
		$state = $this->getStateVariables();
		$validation = $this->getValidation();

		// Mark this subscription attempt in the session
		JFactory::getSession()->set('apply_validation.' . $state->id, 1, 'com_akeebasubs');

		// Step #1.a. Check that the form is valid
		// ----------------------------------------------------------------------
		$isValid = $this->isValid();

		if (!$isValid)
		{
			return false;
		}

		// Step #1.b. Check that the subscription level is allowed
		// ----------------------------------------------------------------------

		// Is this actually an allowed subscription level?
		$allowedLevels = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
			->only_once(1)
			->enabled(1)
			->getItemList();
		$allowed = false;
		if (count($allowedLevels))
		{
			foreach ($allowedLevels as $l)
			{
				if ($l->akeebasubs_level_id == $state->id)
				{
					$allowed = true;
					break;
				}
			}
		}

		if (!$allowed)
		{
			return false;
		}

		// Fetch the level's object, used later on
		$level = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
			->getItem($state->id);

		// Step #2. Check that the payment plugin exists or return false
		// ----------------------------------------------------------------------
		$plugins = $this->getPaymentPlugins();
		$found = false;
		if (!empty($plugins))
		{
			foreach ($plugins as $plugin)
			{
				if ($plugin->name == $state->paymentmethod)
				{
					$found = true;
					break;
				}
			}
		}
		if (!$found)
		{
			return false;
		}

		// Reset the session flag, so that future registrations will merge the
		// data from the database
		JFactory::getSession()->set('firstrun', true, 'com_akeebasubs');

		// Step #2.b. Apply block rules in the Professional release
		// ----------------------------------------------------------------------
		if (F0FModel::getTmpInstance('Blockrules', 'AkeebasubsModel')->isBlocked($state))
		{
			if (version_compare(JVERSION, '3.0', 'ge'))
			{
				throw new Exception(JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
			}
			else
			{
				JError::raiseError('403', JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));
			}
		}

		// Step #3. Create or update a user record
		// ----------------------------------------------------------------------
		$user = JFactory::getUser();
		$this->setState('user', $user);
		$userIsSaved = $this->updateUserInfo(true, $level);
		if (!$userIsSaved)
		{
			return false;
		}
		else
		{
			$user = $this->getState('user', $user);
		}

		// Store the user's ID in the session
		$session = JFactory::getSession();
		$session->set('subscribes.user_id', $user->id, 'com_akeebasubs');

		// Step #4. Create or add user extra fields
		// ----------------------------------------------------------------------
		// Find an existing record
		$dummy = $this->saveCustomFields();

		// Step #5. Check for existing subscription records and calculate the subscription expiration date
		// ----------------------------------------------------------------------
		// First, the question: is this level part of a group?
		$haveLevelGroup = false;
		if ($level->akeebasubs_levelgroup_id > 0)
		{
			// Is the level group published?
			$levelGroup = F0FModel::getTmpInstance('Levelgroups', 'AkeebasubsModel')
				->getItem($level->akeebasubs_levelgroup_id);
			if ($levelGroup instanceof F0FTable)
			{
				$haveLevelGroup = $levelGroup->enabled;
			}
		}

		if ($haveLevelGroup)
		{
			// We have a level group. Get all subscriptions for all levels in
			// the group.
			$subscriptions = array();
			$levelsInGroup = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->levelgroup($level->akeebasubs_levelgroup_id)
				->getList(true);
			foreach ($levelsInGroup as $l)
			{
				$someSubscriptions = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
					->user_id($user->id)
					->level($l->akeebasubs_level_id)
					->paystate('C')
					->getList(true);
				if (count($someSubscriptions))
				{
					$subscriptions = array_merge($subscriptions, $someSubscriptions);
				}
			}
		}
		else
		{
			// No level group found. Get subscriptions on the same level.
			$subscriptions = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
				->user_id($user->id)
				->level($state->id)
				->paystate('C')
				->getList(true);
		}

		$jNow = new JDate();
		$now = $jNow->toUnix();
		$mNow = $jNow->toSql();

		if (empty($subscriptions))
		{
			$startDate = $now;
		}
		else
		{
			$startDate = $now;
			foreach ($subscriptions as $row)
			{
				// Only take into account paid-for subscriptions
				if ($row->state != 'C')
				{
					continue;
				}
				// Calculate the expiration date
				$jDate = new JDate($row->publish_down);
				$expiryDate = $jDate->toUnix();
				// If the subscription expiration date is earlier than today, ignore it
				if ($expiryDate < $now)
				{
					continue;
				}
				// If the previous subscription's expiration date is later than the current start date,
				// update the start date to be one second after that.
				if ($expiryDate > $startDate)
				{
					$startDate = $expiryDate + 1;
				}
				// Also mark the old subscription as "communicated". We don't want
				// to spam our users with subscription renewal notices or expiration
				// notification after they have effectively renewed!
				F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
					->setId($row->akeebasubs_subscription_id)
					->getItem()
					->save(array(
						'contact_flag' => 3
					));
			}
		}

		// Step #6. Create a new subscription record
		// ----------------------------------------------------------------------
		$nullDate = JFactory::getDbo()->getNullDate();
		$level = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
			->setId($state->id)
			->getItem();
		if ($level->forever)
		{
			$jStartDate = new JDate();
			$endDate = '2038-01-01 00:00:00';
		}
		elseif (!is_null($level->fixed_date) && ($level->fixed_date != $nullDate))
		{
			$jStartDate = new JDate();
			$endDate = $level->fixed_date;
		}
		else
		{
			$jStartDate = new JDate($startDate);

			// Subscription duration (length) modifiers, via plugins
			$duration_modifier = 0;
			JLoader::import('joomla.plugin.helper');
			JPluginHelper::importPlugin('akeebasubs');
			$app = JFactory::getApplication();
			$jResponse = $app->triggerEvent('onValidateSubscriptionLength', array($state));
			if (is_array($jResponse) && !empty($jResponse))
			{
				foreach ($jResponse as $pluginResponse)
				{
					if (empty($pluginResponse))
					{
						continue;
					}
					$duration_modifier += $pluginResponse;
				}
			}

			// Calculate the effective duration
			$duration = (int)$level->duration + $duration_modifier;
			if ($duration <= 0)
			{
				$duration = 0;
			}

			$duration = $duration * 3600 * 24;
			$endDate = $startDate + $duration;
		}

		$mStartDate = $jStartDate->toSql();
		$jEndDate = new JDate($endDate);
		$mEndDate = $jEndDate->toSql();

		// Store the price validation's "oldsub" and "expiration" keys in
		// the subscriptions subcustom array
		$subcustom = $state->subcustom;
		if (empty($subcustom))
		{
			$subcustom = array();
		}
		elseif (is_object($subcustom))
		{
			$subcustom = (array)$subcustom;
		}
		$priceValidation = $this->validatePrice();
		$subcustom['fixdates'] = array(
			'oldsub'     => $priceValidation->oldsub,
			'allsubs'    => $priceValidation->allsubs,
			'expiration' => $priceValidation->expiration,
		);

		// Serialise custom subscription parameters
		$custom_subscription_params = json_encode($subcustom);

		// Setup the new subscription
		$data = array(
			'akeebasubs_subscription_id' => null,
			'user_id'                    => $user->id,
			'akeebasubs_level_id'        => $state->id,
			'publish_up'                 => $mStartDate,
			'publish_down'               => $mEndDate,
			'notes'                      => '',
			'enabled'                    => ($validation->price->gross < 0.01) ? 1 : 0,
			'processor'                  => ($validation->price->gross < 0.01) ? 'none' : $state->paymentmethod,
			'processor_key'              => ($validation->price->gross < 0.01) ? $this->_uuid(true) : '',
			'state'                      => ($validation->price->gross < 0.01) ? 'C' : 'N',
			'net_amount'                 => $validation->price->net - $validation->price->discount,
			'tax_amount'                 => $validation->price->tax,
			'gross_amount'               => $validation->price->gross,
			'recurring_amount'           => $validation->price->recurring,
			'tax_percent'                => $validation->price->taxrate,
			'created_on'                 => $mNow,
			'params'                     => $custom_subscription_params,
			'akeebasubs_coupon_id'       => $validation->price->couponid,
			'akeebasubs_upgrade_id'      => $validation->price->upgradeid,
			'contact_flag'               => 0,
			'prediscount_amount'         => $validation->price->net,
			'discount_amount'            => $validation->price->discount,
			'first_contact'              => '0000-00-00 00:00:00',
			'second_contact'             => '0000-00-00 00:00:00',
			'akeebasubs_affiliate_id'    => 0,
			'affiliate_comission'        => 0
		);

		$subscription = F0FModel::getTmpInstance('Subscriptions', 'AkeebasubsModel')
			->getTable();
		$subscription->reset();
		$subscription->akeebasubs_subscription_id = 0;
		$subscription->_dontCheckPaymentID = true;
		$result = $subscription->save($data);
		$this->_item = $subscription;

		// Step #7. Hit the coupon code, if a coupon is indeed used
		// ----------------------------------------------------------------------
		if ($validation->price->couponid)
		{
			F0FModel::getTmpInstance('Coupons', 'AkeebasubsModel')
				->setId($validation->price->couponid)
				->getItem()
				->hit();
		}

		// Step #8. Clear the session
		// ----------------------------------------------------------------------
		$session = JFactory::getSession();
		$session->set('validation_cache_data', null, 'com_akeebasubs');
		$session->set('apply_validation.' . $state->id, null, 'com_akeebasubs');

		// Step #9. Call the specific plugin's onAKPaymentNew() method and get the redirection URL,
		//          or redirect immediately on auto-activated subscriptions
		// ----------------------------------------------------------------------
		if ($subscription->gross_amount != 0)
		{
			// Non-zero charges; use the plugins
			$app = JFactory::getApplication();
			$jResponse = $app->triggerEvent('onAKPaymentNew', array(
				$state->paymentmethod,
				$user,
				$level,
				$subscription
			));
			if (empty($jResponse))
			{
				return false;
			}

			foreach ($jResponse as $response)
			{
				if ($response === false)
				{
					continue;
				}

				$this->paymentForm = $response;
			}
		}
		else
		{
			// Zero charges. First apply subscription replacement
			if (!class_exists('plgAkpaymentAbstract'))
			{
				require_once JPATH_ADMINISTRATOR . '/components/com_akeebasubs/assets/akpayment.php';
			}
			$updates = array();
			plgAkpaymentAbstract::fixSubscriptionDates($subscription, $updates);

			if (!empty($updates))
			{
				$result = $subscription->save($updates);
				$this->_item = $subscription;
			}

			// and then just redirect
			$app = JFactory::getApplication();
			$slug = F0FModel::getTmpInstance('Levels', 'AkeebasubsModel')
				->setId($subscription->akeebasubs_level_id)
				->getItem()
				->slug;
			$app->redirect(str_replace('&amp;', '&', JRoute::_('index.php?option=com_akeebasubs&layout=default&view=message&slug=' . $slug . '&layout=order&subid=' . $subscription->akeebasubs_subscription_id)));

			return false;
		}

		// Return true
		// ----------------------------------------------------------------------
		return true;
	}

	public function runCancelRecurring()
	{
		$state = $this->getStateVariables();

		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$data = array_merge($rawDataGet, $rawDataPost);

		// Some plugins result in an empty Itemid being added to the request
		// data, screwing up the payment callback validation in some cases (e.g.
		// PayPal).
		if (array_key_exists('Itemid', $data))
		{
			if (empty($data['Itemid']))
			{
				unset($data['Itemid']);
			}
		}

		$this->getPaymentPlugins();

		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentCancelRecurring', array(
			$state->paymentmethod,
			$data
		));

		if (empty($jResponse))
		{
			return false;
		}

		$status = false;

		foreach ($jResponse as $response)
		{
			$status = $status || $response;
		}

		return $status;
	}

	/**
	 * Runs a payment callback
	 */
	public function runCallback()
	{
		$state = $this->getStateVariables();

		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$data = array_merge($rawDataGet, $rawDataPost);

		// Some plugins result in an empty Itemid being added to the request
		// data, screwing up the payment callback validation in some cases (e.g.
		// PayPal).
		if (array_key_exists('Itemid', $data))
		{
			if (empty($data['Itemid']))
			{
				unset($data['Itemid']);
			}
		}

		$dummy = $this->getPaymentPlugins();

		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentCallback', array(
			$state->paymentmethod,
			$data
		));
		if (empty($jResponse))
		{
			return false;
		}

		$status = false;

		foreach ($jResponse as $response)
		{
			$status = $status || $response;
		}

		return $status;
	}

	/**
	 * Get the form set by the active payment plugin
	 */
	public function getPaymentForm()
	{
		return $this->paymentForm;
	}

	/**
	 * Returns the state data.
	 */
	public function getData()
	{
		return $this->getStateVariables();
	}

	/**
	 * Generates a Universally Unique IDentifier, version 4.
	 *
	 * This function generates a truly random UUID.
	 *
	 * @paream boolean    If TRUE return the uuid in hex format, otherwise as a string
	 * @see    http://tools.ietf.org/html/rfc4122#section-4.4
	 * @see    http://en.wikipedia.org/wiki/UUID
	 * @return string A UUID, made up of 36 characters or 16 hex digits.
	 */
	protected function _uuid($hex = false)
	{
		$pr_bits = false;
		if (is_resource($this->_urand))
		{
			$pr_bits .= @fread($this->_urand, 16);
		}

		if (!$pr_bits)
		{
			$fp = @fopen('/dev/urandom', 'rb');
			if ($fp !== false)
			{
				$pr_bits .= @fread($fp, 16);
				@fclose($fp);
			}
			else
			{
				// If /dev/urandom isn't available (eg: in non-unix systems), use mt_rand().
				$pr_bits = "";
				for ($cnt = 0; $cnt < 16; $cnt++)
				{
					$pr_bits .= chr(mt_rand(0, 255));
				}
			}
		}

		$time_low = bin2hex(substr($pr_bits, 0, 4));
		$time_mid = bin2hex(substr($pr_bits, 4, 2));
		$time_hi_and_version = bin2hex(substr($pr_bits, 6, 2));
		$clock_seq_hi_and_reserved = bin2hex(substr($pr_bits, 8, 2));
		$node = bin2hex(substr($pr_bits, 10, 6));

		/**
		 * Set the four most significant bits (bits 12 through 15) of the
		 * time_hi_and_version field to the 4-bit version number from
		 * Section 4.1.3.
		 *
		 * @see http://tools.ietf.org/html/rfc4122#section-4.1.3
		 */
		$time_hi_and_version = hexdec($time_hi_and_version);
		$time_hi_and_version = $time_hi_and_version >> 4;
		$time_hi_and_version = $time_hi_and_version | 0x4000;

		/**
		 * Set the two most significant bits (bits 6 and 7) of the
		 * clock_seq_hi_and_reserved to zero and one, respectively.
		 */
		$clock_seq_hi_and_reserved = hexdec($clock_seq_hi_and_reserved);
		$clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
		$clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;

		//Either return as hex or as string
		$format = $hex ? '%08s%04s%04x%04x%012s' : '%08s-%04s-%04x-%04x-%012s';

		return sprintf($format, $time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node);
	}

	private function validEmail($email)
	{
		$isValid = true;
		$atIndex = strrpos($email, "@");
		if (is_bool($atIndex) && !$atIndex)
		{
			$isValid = false;
		}
		else
		{
			$domain = substr($email, $atIndex + 1);
			$local = substr($email, 0, $atIndex);
			$localLen = strlen($local);
			$domainLen = strlen($domain);
			if ($localLen < 1 || $localLen > 64)
			{
				// local part length exceeded
				$isValid = false;
			}
			else if ($domainLen < 1 || $domainLen > 255)
			{
				// domain part length exceeded
				$isValid = false;
			}
			else if ($local[0] == '.' || $local[$localLen - 1] == '.')
			{
				// local part starts or ends with '.'
				$isValid = false;
			}
			else if (preg_match('/\\.\\./', $local))
			{
				// local part has two consecutive dots
				$isValid = false;
			}
			else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain))
			{
				// character not valid in domain part
				$isValid = false;
			}
			else if (preg_match('/\\.\\./', $domain))
			{
				// domain part has two consecutive dots
				$isValid = false;
			}
			else if
			(!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/',
				str_replace("\\\\", "", $local))
			)
			{
				// character not valid in local part unless
				// local part is quoted
				if (!preg_match('/^"(\\\\"|[^"])+"$/',
					str_replace("\\\\", "", $local))
				)
				{
					$isValid = false;
				}
			}

			// Check the domain name
			if ($isValid && !$this->is_valid_domain_name($domain))
			{
				return false;
			}

			// Uncomment below to have PHP run a proper DNS check (risky on shared hosts!)
			/**
			 * if ($isValid && !(checkdnsrr($domain,"MX") || checkdnsrr($domain,"A"))) {
			 * // domain not found in DNS
			 * $isValid = false;
			 * }
			 * /**/
		}

		return $isValid;
	}

	function is_valid_domain_name($domain_name)
	{
		$pieces = explode(".", $domain_name);
		foreach ($pieces as $piece)
		{
			if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $piece)
				|| preg_match('/-$/', $piece)
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Send an activation email to the user
	 *
	 * @param JUser $user
	 */
	private function sendActivationEmail($user, $indata)
	{
		$app = JFactory::getApplication();
		$config = JFactory::getConfig();
		$db = JFactory::getDbo();
		$params = JComponentHelper::getParams('com_users');

		$data = array_merge((array)$user->getProperties(), $indata);

		$useractivation = $params->get('useractivation');
		$sendpassword = $params->get('sendpassword', 1);

		// Check if the user needs to activate their account.
		if (($useractivation == 1) || ($useractivation == 2))
		{
			$user->activation = JApplication::getHash(JUserHelper::genRandomPassword());
			$user->block = 1;
			$user->lastvisitDate = JFactory::getDbo()->getNullDate();
		}
		else
		{
			$user->block = 0;
		}

		// Load the users plugin group.
		JPluginHelper::importPlugin('user');

		// Store the data.
		if (!$user->save())
		{
			return false;
		}

		// Compile the notification mail values.
		$data = $user->getProperties();
		$data['password_clear'] = $indata['password2'];
		$data['fromname'] = $config->get('fromname');
		$data['mailfrom'] = $config->get('mailfrom');
		$data['sitename'] = $config->get('sitename');
		$data['siteurl'] = JUri::root();

		// Load com_users translation files
		$jlang = JFactory::getLanguage();
		$jlang->load('com_users', JPATH_SITE, 'en-GB', true); // Load English (British)
		$jlang->load('com_users', JPATH_SITE, $jlang->getDefault(), true); // Load the site's default language
		$jlang->load('com_users', JPATH_SITE, null, true); // Load the currently selected language

		// Handle account activation/confirmation emails.
		if ($useractivation == 2)
		{
			$uri = JURI::getInstance();
			$base = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));
			$data['activate'] = $base . JRoute::_('index.php?option=com_users&task=registration.activate&token=' . $data['activation'], false);

			$emailSubject = JText::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
			);

			if ($sendpassword)
			{
				$emailBody = JText::sprintf(
					'COM_USERS_EMAIL_REGISTERED_WITH_ADMIN_ACTIVATION_BODY',
					$data['name'],
					$data['sitename'],
					$data['activate'],
					$data['siteurl'],
					$data['username'],
					$data['password_clear']
				);
			}
			else
			{
				$emailBody = JText::sprintf(
					'COM_USERS_EMAIL_REGISTERED_WITH_ADMIN_ACTIVATION_BODY_NOPW',
					$data['name'],
					$data['sitename'],
					$data['activate'],
					$data['siteurl'],
					$data['username']
				);
			}
		}
		elseif ($useractivation == 1)
		{
			// Set the link to activate the user account.
			$uri = JURI::getInstance();
			$base = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));
			$data['activate'] = $base . JRoute::_('index.php?option=com_users&task=registration.activate&token=' . $data['activation'], false);

			$emailSubject = JText::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
			);

			if ($sendpassword)
			{
				$emailBody = JText::sprintf(
					'COM_USERS_EMAIL_REGISTERED_WITH_ACTIVATION_BODY',
					$data['name'],
					$data['sitename'],
					$data['activate'],
					$data['siteurl'],
					$data['username'],
					$data['password_clear']
				);
			}
			else
			{
				$emailBody = JText::sprintf(
					'COM_USERS_EMAIL_REGISTERED_WITH_ACTIVATION_BODY_NOPW',
					$data['name'],
					$data['sitename'],
					$data['activate'],
					$data['siteurl'],
					$data['username']
				);
			}
		}
		else
		{

			$emailSubject = JText::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
			);

			if (version_compare(JVERSION, '3.0', 'lt'))
			{
				$emailBody = JText::sprintf(
					'COM_USERS_EMAIL_REGISTERED_BODY',
					$data['name'],
					$data['sitename'],
					$data['siteurl']
				);
			}
			else
			{
				if ($sendpassword)
				{
					$emailBody = JText::sprintf(
						'COM_USERS_EMAIL_REGISTERED_BODY',
						$data['name'],
						$data['sitename'],
						$data['siteurl'],
						$data['username'],
						$data['password_clear']
					);
				}
				else
				{
					$emailBody = JText::sprintf(
						'COM_USERS_EMAIL_REGISTERED_BODY_NOPW',
						$data['name'],
						$data['sitename'],
						$data['siteurl']
					);
				}
			}
		}

		// Send the registration email.
		$return = JFactory::getMailer()->sendMail($data['mailfrom'], $data['fromname'], $data['email'], $emailSubject, $emailBody);

		//Send Notification mail to administrators
		if (($params->get('useractivation') < 2) && ($params->get('mail_to_admin') == 1))
		{
			$emailSubject = JText::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
			);

			$emailBodyAdmin = JText::sprintf(
				'COM_USERS_EMAIL_REGISTERED_NOTIFICATION_TO_ADMIN_BODY',
				$data['name'],
				$data['username'],
				$data['siteurl']
			);

			// get all admin users
			$query = $db->getQuery(true);
			$query->select($db->quoteName(array('name', 'email', 'sendEmail', 'id')))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('sendEmail') . ' = ' . 1);

			$db->setQuery($query);

			try
			{
				$rows = $db->loadObjectList();
			}
			catch (RuntimeException $e)
			{
				return false;
			}

			// Send mail to all superadministrators id
			foreach ($rows as $row)
			{
				$return = JFactory::getMailer()->sendMail($data['mailfrom'], $data['fromname'], $row->email, $emailSubject, $emailBodyAdmin);
			}
		}

		return $return;
	}
}
