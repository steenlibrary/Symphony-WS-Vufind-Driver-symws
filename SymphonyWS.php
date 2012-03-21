<?php
require_once 'Interface.php';

/**
 * Symphony Web Services (symws) ILS Driver
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Michael Gillen <mlgillen@sfasu.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */

class SymphonyWS implements DriverInterface
{
    private $config;	
    private $WS;
    private $session;

    private $STANDARD_WSDL = "soap/standard?wsdl";
    private $SECURITY_WSDL = "soap/security?wsdl";
    private $PATRON_WSDL = "soap/patron?wsdl";
    private $ADMIN_WSDL = "soap/admin?wsdl";

    private $WS_HEADER = "http://www.sirsidynix.com/xmlns/common/header";

    private $BASE_URL;
    private $proxyhost;  
    private $proxyport;
    private $clientID;
	
    private $standardService;
    private $securityService;
    private $patronService;
    private $adminService;
  
    private $login;

    function __construct()
    {
        // Load Configuration for this Module
        $this->config = parse_ini_file('conf/SymphonyWS.ini', true);
	$host = $this->config['WebServices']['host'];
	$port = $this->config['WebServices']['port'];
	$app = $this->config['WebServices']['app'];
	
 	$this->BASE_URL = ($this->config['WebServices']['https']) ? "https://" : "http://" 
				  .$host.":".$port."/symws/";
	$this->proxyhost = $host.":".$port."/symws/soap/standard";
	$this->proxyport = $port;
	$this->clientID = $app;
        
        try {
	    $headerbody = array("clientID" => $this->clientID);     

	    $options = array("proxy_host" => $this->proxyhost,
			     "proxy_port" => $this->proxyport, 
			     "trace" => 1);
  
	    $header = new SoapHeader($this->WS_HEADER, "SdHeader", $headerbody);
 
	    $this->standardService = new SoapClient($this->BASE_URL.$this->STANDARD_WSDL,$options);           
	    $this->standardService->__setSoapHeaders($header);
			
	    $this->securityService = new SoapClient($this->BASE_URL.$this->SECURITY_WSDL,$options);           
	    $this->securityService->__setSoapHeaders($header);

	    $this->adminService = new SoapClient($this->BASE_URL.$this->ADMIN_WSDL, $options);           
	    $this->adminService->__setSoapHeaders($header); 

        } catch (PDOException $e) {
            return $e;
        }
    }

    public function getLibraries()
    {
	try {
	    $libraryList = array();
	    $libraries = $this->adminService->lookupLibraryPolicyList();
	    foreach($libraries as $library){
	        foreach($library as $libraryCode){
	            $libraryList[$libraryCode->policyID] = $libraryCode->policyDescription;
	        }
            }
	    return $libraryList;
        } catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    public function getLocations()
    {
	  try {
	    $locationList = array();
	    $locations = $this->adminService->lookupLocationPolicyList();
	    foreach($locations as $location){
	      foreach($location as $locationCode){
	        $locationList[$locationCode->policyID] = $locationCode->policyDescription;
	      }
	    }
	    return $locationList;
	  } catch (PDOException $e) {
              return new PEAR_Error($e->getMessage());
          }
    }

    public function getTypes()
    {
	  try {
	    $typesList = array();
	    $types = $this->adminService->lookupItemTypePolicyList();
	    foreach($types as $type){
	      foreach($type as $typeCode){
	        $typesList[$typeCode->policyID] = $typeCode->policyDescription;
	      }
	    }
	    return $typesList;
	  } catch (PDOException $e) {
              return new PEAR_Error($e->getMessage());
          }
    }

    public function getHolding($id, $patron = false) 
    {
	try {
	    $parentID = null;
	    $parentTitle = null;
            $locations = $this->getLocations();
    	    $types = $this->getTypes();
	    $libraries = $this->getLibraries();
	    $holdingList = array();

	    $result = $this->standardService->lookupTitleInfo(
					array("titleID" => $id,
				      	      "includeAvailabilityInfo" => "true",
					      "includeItemInfo" => "true",
					      "includeOrderInfo" => "true",
					      "includeOPACInfo" => "true",
					      "includeBoundTogether" => "true",
					      "includeMarcHoldings" => "true",
					      "marcEntryFilter" => "NONE"));

	    $holdings = $result->TitleInfo->CallInfo;

	    $action = (isset($_GET['action'])) ? $_GET['action'] : 'Home';
	    $action = preg_replace('/[^\w]/', '', $action);

	    $baseCallNumber = $result->TitleInfo->baseCallNumber;
	    
	    if($result->TitleInfo->numberOfBoundwithLinks > 0)
	    {
		$boundWidthHoldings = $result->TitleInfo->BoundwithLinkInfo;
		if(!is_array($boundWidthHoldings))
   	          $boundWidthHoldings = array($boundWidthHoldings);
		foreach ($boundWidthHoldings as $CallInfo)
	    	{
			if($CallInfo->linkedAsParent == 1)
			{
			  $parentID = $CallInfo->linkedTitle->titleID;
			  $parentTitle = $CallInfo->linkedTitle->title;
			  $callNumberFilter = $CallInfo->callNumber;
			}
		}
	    }

	    if($parentID){
		//$result = $this->WS->lookupTitleInfo($parentID);
		$options = array("titleID" => $parentID,
				 "includeAvailabilityInfo" => "true",
				 "includeItemInfo" => "true",
				 "includeOrderInfo" => "true",
				 "includeOPACInfo" => "true",
				 "includeBoundTogether" => "true",
				 "includeMarcHoldings" => "true",
				 "marcEntryFilter" => "NONE");
		$result = $this->standardService->lookupTitleInfo($options);
		$holdings = $result->TitleInfo->CallInfo;
	    }

	    if(!is_array($holdings))
   	        $holdings = array($holdings);

	    foreach ($holdings as $CallInfo)
	    {
	        $items = $CallInfo->ItemInfo;

		if(!is_array($items))
   	  	    $items = array($items);
		
		foreach($items as $ItemInfo)
		{
		    if($ItemInfo != null && $ItemInfo->itemID != null)
		    {
		        if($ItemInfo->chargeable != 1)
			{
			    $available = false;
			    $addLink = true;
			    $status = "Checked Out";
			}
			else
			{
			    $available = true;
			    $addLink = false;
			    $status = "Available";
			}
			if(isset($ItemInfo->dueDate))
			    $dueDate = date("F j, Y", strtotime($ItemInfo->dueDate));
			else
		    	    $dueDate = null;
			//print_r($ItemInfo);

			if(!isset($callNumberFilter) || ($CallInfo->callNumber == $callNumberFilter) || ($parentID == $id))
			{

			if(($action == "JSON") && ($this->config['Behaviors']['showBaseCallNumber'] == true))
			  $callNumber = $baseCallNumber;
			else
			  $callNumber = $CallInfo->callNumber;

                        $holdingList[] = array('id' => $id,
					       'item_id' => $ItemInfo->itemID,
				               'availability' => $available,
				               'item_num' => $ItemInfo->itemID,
				               'status' => $status,
				               'library' => $libraries[$CallInfo->libraryID],
				               'location' => $locations[$ItemInfo->currentLocationID],
				               'material' => $types[$ItemInfo->itemTypeID],
				               'reserve' => "N",
				               'callnumber' => $callNumber,
					       'bound_with_id' => $parentID, // Added
					       'bound_with_title' => $parentTitle, // Added
				               'collection' => 1,
				               'duedate' => $dueDate,
					       'barcode' => $ItemInfo->itemID,
					       'number' => 1,
					       'holdtype' => 'hold',
					       'addLink' => $addLink
					      );
			}
		    }
	        }
	    }
            //print_r($holdingList);
	    return $holdingList;	   
	} catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
	}
    }

    public function getHoldings($idList)
    {
        foreach ($idList as $id) {
            $holdings[] = $this->getHolding($id);
        }
        return $holdings;
    }

    public function getStatus($id)
    {
        return $this->getHolding($id);
    }

    public function getStatuses($idList)
    {
        $status = array();
        foreach ($idList as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }

    public function getPurchaseHistory($id)
    {
        return array();
    }

    public function patronLogin($username, $password)
    { 
        try {
	    $this->login = $this->securityService->loginUser(array("login" => $username, "password" => $password));
	
	    $headerbody = array("clientID" => $this->clientID, 
				"sessionToken" => $this->login->sessionToken);
	    $header = new SoapHeader($this->WS_HEADER, "SdHeader", $headerbody);
			
	    $this->patronService = new SoapClient($this->BASE_URL.$this->PATRON_WSDL,
				array("proxy_host" => "$this->proxyhost","proxy_port" => $this->proxyport, "trace" => 1));           
	    $this->patronService->__setSoapHeaders($header);

	    $login = $this->login;

	    if(isset($login->sessionToken))
	    {
	      //$account = $this->WS->lookupMyAccountInfo();
	      $account = $this->patronService->lookupMyAccountInfo(array("includePatronInfo" => "ALL",
																	  "includePatronCirculationInfo" => "ALL",
																	  "includePatronCheckoutInfo" => "ALL", 
																	  "includePatronHoldInfo" => "ACTIVE", 
																	  "includePatronAddressInfo" => "ACTIVE",
																	  "includeFeeInfo" => "ACTIVE",
																	  "includePatronStatusInfo" => "ALL"));

	      list($lastname,$firstname) = explode(', ',$account->patronInfo->displayName);

	      $user = array('id' => $account->patronInfo->userID,
                            'firstname' => $firstname,
                            'lastname' => $lastname,
                            'cat_username' => $username,
                            'cat_password' => $password,
                            'email' => null,
                            'major' => null,
                            'college' => null);
	      return $user;
            }
	    else
	      return null;
	    
        } catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     *
     * @return array An array with key-value pairs.
     * @access public
     */
    public function getConfig($function)
    {
        if (isset($this->config[$function]) ) {
            $functionConfig = $this->config[$function];
        } else {
            $functionConfig = false;
        }
        return $functionConfig;
    }

    /**
     * Get Pick Up Locations
     *
     * This is responsible get a list of valid library locations for holds / recall
     * retrieval
     *
     * @param array $patron      Patron information returned by the patronLogin
     * method.
     * @param array $holdDetails Optional array, only passed in when getting a list
     * in the context of placing a hold; contains most of the same values passed to
     * placeHold, minus the patron data.  May be used to limit the pickup options
     * or may be ignored.  The driver must not add new options to the return array
     * based on this data or other areas of VuFind may behave incorrectly.
     *
     * @return array        An array of associative arrays with locationID and
     * locationDisplay keys
     * @access public
     */
    public function getPickUpLocations($patron = false, $holdDetails = null)
    {
        $libraries = array();

	foreach($this->getLibraries() as $key=>$library)
	{
	    $libraries[] = array(
                'locationID' => $key,
                'locationDisplay' => $library
            );
	}
 
        return $libraries;
    }

    /**
     * Get Cancel Hold Form
     *
     * Supplies the form details required to cancel a hold
     *
     * @param array $holdDetails An array of item data
     *
     * @return string  Data for use in a form field
     * @access public
     */
    public function getCancelHoldDetails($holdDetails)
    {
        return $holdDetails['reqnum'];
    }

    /**
     * Cancel Holds
     *
     * Attempts to Cancel a hold on a particular item
     *
     * @param array $cancelDetails An array of item and patron data
     *
     * @return mixed  An array of data on each request including
     * whether or not it was successful and a system message (if available)
     * or boolean false on failure
     * @access public
     */
    public function cancelHolds($cancelDetails)
    {
	$count = 0;
        $items = array();

	foreach($cancelDetails['details'] as $holdKey)
	{
	  //$hold = $this->WS->cancelMyHold($holdKey);
	  $hold = $this->patronService->cancelMyHold(array("holdKey" => "$holdKey"));
	  
	  if($hold == 1) // true or 1?
	  {
            $count++;
            $items[$holdKey] = array(
              'success' => true, 'status' => "hold_cancel_success"
            );
          }
	  else
          {
            $items[$holdKey] = array(
              'success' => false, 'status' => "hold_cancel_fail"
            );
          }
	}
	$result = array('count' => $count, 'items' => $items);
        return $result;
    }

    public function getMyHolds($patron)
    {
	//$result = $this->WS->listMyHolds();
	$result = $this->patronService->lookupMyAccountInfo(array("includePatronHoldInfo" => "ACTIVE"));

	if(!property_exists($result, "patronHoldInfo"))
	  return null;

        $holds = $result->patronHoldInfo;

	if(!is_array($holds))
   	  $holds  = array($holds);
	//print_r($holds);
	foreach($holds as $hold){
	  $holdList[] = array('id' => $hold->titleKey,
			      //'type' => ,
			       'location' => $hold->pickupLibraryID,
			       //??'pickup' => $hold->pickupLibraryID,
			       'locationID' => $hold->pickupLibraryID,
			       'locationDisplay'=> $hold->pickupLibraryDescription,
			       'reqnum' => $hold->holdKey,
			       'expire' => date("F j, Y", strtotime($hold->expiresDate)),
			       'create' => date("F j, Y", strtotime($hold->placedDate)),
			       'position' => $hold->queuePosition,
			       'available' => $hold->available,
			       'item_id' => $hold->itemID,
			       //'volume' => ,
			       //'publication_year' => ,
			       'title' => $hold->title
			     );
	}
	return $holdList;
    }

    public function getMyFines($patron)
    {
	try {
	  //$result = $this->WS->lookupMyAccountInfo();
	  $result = $this->patronService->lookupMyAccountInfo(array("includePatronInfo" => "ALL",
																	  "includePatronCirculationInfo" => "ALL",
																	  "includePatronCheckoutInfo" => "ALL", 
																	  "includePatronHoldInfo" => "ACTIVE", 
																	  "includePatronAddressInfo" => "ACTIVE",
																	  "includeFeeInfo" => "ACTIVE",
																	  "includePatronStatusInfo" => "ALL"));

	  $fines = $result->patronCirculationInfo;
  	  if($fines->numberOfFees > 0)
	  { 
		$estimatedFines = $fines->estimatedFines->_;

		$fineList[] = array('id' => 0,
		                    'amount' => $estimatedFines*100,
				    //'checkout' => ,
		                    'fine' => "General Fine",
		                    'balance' => $estimatedFines*100,
		                    //'createdate' => $checkout,
		                    //'duedate' => $duedate
				    );

	     return $fineList;
	  }
	  else
		return null;
	} catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
	}
    }
    
    public function getMyProfile($patron)
    {
	try {
	  //$result = $this->WS->lookupMyAccountInfo();
         $result = $this->patronService->lookupMyAccountInfo(array("includePatronInfo" => "ALL",
																	  "includePatronCirculationInfo" => "ALL",
																	  "includePatronCheckoutInfo" => "ALL", 
																	  "includePatronHoldInfo" => "ACTIVE", 
																	  "includePatronAddressInfo" => "ACTIVE",
																	  "includeFeeInfo" => "ACTIVE",
																	  "includePatronStatusInfo" => "ALL"));

	  $addressInfo = $result->patronAddressInfo->Address1Info;
	  $address1 = $addressInfo[0]->addressValue;
	  $address2 = $addressInfo[1]->addressValue;
	  $zip = $addressInfo[2]->addressValue;
	  $phone = $addressInfo[3]->addressValue;
	
	  list($lastname,$firstname) = explode(', ', $result->patronInfo->displayName);
	
	  $profile = array('lastname' => $lastname,
                           'firstname' => $firstname,
                           'address1' => $address1,
                           'address2' => $address2,
                           'zip' => $zip,
                           'phone' => $phone,
                           'group' => $result->patronInfo->groupID);
          return $profile;
	} catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    public function getMyTransactions($patron)
    {
	try {
	//$result = $this->WS->lookupMyAccountInfo();
	$result = $this->patronService->lookupMyAccountInfo(array("includePatronInfo" => "ALL",
																	  "includePatronCirculationInfo" => "ALL",
																	  "includePatronCheckoutInfo" => "ALL", 
																	  "includePatronHoldInfo" => "ACTIVE", 
																	  "includePatronAddressInfo" => "ACTIVE",
																	  "includeFeeInfo" => "ACTIVE",
																	  "includePatronStatusInfo" => "ALL"));

	$transactions = $result->patronCheckoutInfo;
	//print_r($transactions);
	
	if(empty($transactions))
	  return null;

	if(!is_array($transactions))
   	  $transactions  = array($transactions);

	foreach($transactions as $transaction)
	{

	  if($transaction->renewalsRemaining > 0)
	    $renew = true;
	  else
            $renew = false;

	  $transList[] = array('id' => $transaction->titleKey,
			       'duedate' => date("F j, Y", strtotime($transaction->dueDate)),
		               'barcode' => $transaction->itemID,
		               'renew' => $transaction->unseenRenewalsRemaining,
				//'request' => $row2['REQUEST'],
				//'volume' => $row2['REQUEST'],
				//'publication_year' => $row2['REQUEST'],
			       'renewable' => $renew,
			       'number_of_renewals' => $transaction->unseenRenewalsRemaining,
   			        //'message' => $row2['REQUEST'],
			       'title' => $transaction->title
			      );
	}
	    return $transList;
	} catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
	}
    }
    
   public function getRenewDetails($holdDetails)
   {
        return $holdDetails['barcode'];
   }
   
   public function renewMyItems($renewDetails)
   {
	$count = 0;
        $items = array();

	foreach($renewDetails['details'] as $barcode)
	{
	  //$renewal = $this->WS->renewMyCheckout($barcode);
	  $result = $this->patronService->renewMyCheckout(array("itemID" => $barcode));

	  if(isset($renewal->message))
	  {
            $count++;
            $items[$barcode] = array(
              'success' => true, 
	      'status' => "hold_cancel_success",
	      'new_date' => date("j-M-y", strtotime($renewal->dueDate)),
	      'new_time' =>date("g:i a", strtotime($renewal->dueDate)),
	      'item_id' => $barcode,
	      'sysMessage' => $renewal->message
            );
          }
	  else
          {
            $items[$barcode] = array(
              'success' => false,
	      'new_date' => false,
	      'new_time' => false,
	      'sysMessage' => $renewal
            );
          }
	}
	$result = array('count' => $count, 'items' => $items);
        return $result;
   }
   
   public function renewItem($patron_id, $specific_item) 
   {
	//return $this->WS->renewCheckout($patron_id, $specific_item);
        return $this->patronService->renewCheckout(
			array("itemID" => $specific_item, 
			      "userID" => $patron_id)
			);
   }

   public function getHoldLink($id){
	return $this->config['Holds']['hold_link'].$id;
   }

   public function placeHold($holdDetails) 
   {
	try {
	  $options = array();
	
	  if($holdDetails['item_id'] != null)
	    $options["itemID"] = $holdDetails['item_id'];
	  if($holdDetails['id'] != null)
	    $options["titleID"] = $holdDetails['id'];
	  if($holdDetails['pickUpLocation'] != null)
            $options["pickupLibraryID"] = $holdDetails['pickUpLocation'];
	  if($holdDetails['requiredBy'] != null)
	    $options["expiresDate"] = $holdDetails['requiredBy'];

	  //print_r($options);
	  //$hold = $this->WS->createMyHold($options);
	  $hold = $this->patronService->createMyHold($options);
	  if (PEAR::isError($hold)) {
	    PEAR::raiseError(new PEAR_Error('Cannot Process Place Hold - ' . $hold));
	    //return false;
	    /*
	    $result = array(
		'success' => false,
                'hold' => false,
                'reason' =>
                    'We could not place the hold. ' . $hold
            );
	   */
	  }
	  else
	  {
		  $result = array('success' => true,
				  'hold' => $hold,
		        	  'reason' => 'Your hold has been placed.',
		        	  //'reqnum' => $reqnum,
		        	  'lib' => $options["pickupLibraryID"]);
	  }
	  return $result;
        } catch (PDOException $e) {
            return new PEAR_Error($e->getMessage());
        }
   }
}
?>
