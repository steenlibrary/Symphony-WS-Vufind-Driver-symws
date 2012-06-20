<?php
/**
 * Symphony Web Services (symws) ILS Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2007.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Michael Gillen <mlgillen@sfasu.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */
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
    protected $config;

    protected $STANDARD_WSDL = "soap/standard?wsdl";
    protected $SECURITY_WSDL = "soap/security?wsdl";
    protected $PATRON_WSDL   = "soap/patron?wsdl";
    protected $ADMIN_WSDL    = "soap/admin?wsdl";
    protected $RESERVE_WSDL  = "soap/reserve?wsdl";

    protected $WS_HEADER = "http://www.sirsidynix.com/xmlns/common/header";

    protected $BASE_URL;
    protected $clientID;
    protected $sessionToken;

    protected $standardService;
    protected $securityService;
    protected $patronService;
    protected $adminService;
    protected $reserveService;
  
    /**
     * Constructor
     *
     * @param string $configFile The location of an alternative config file
     *
     * @access public
     */
    public function __construct($configFile = false)
    {
        if ($configFile) {
            // Load Configuration passed in
            $this->config = parse_ini_file('conf/'.$configFile, true);
        } else {
            // Default Configuration
            $this->config = parse_ini_file('conf/SymphonyWS.ini', true);
        }

        $host = $this->config['WebServices']['host'];
        $port = $this->config['WebServices']['port'];

        $this->clientID = $this->config['WebServices']['clientID'];
        $this->BASE_URL = ($this->config['WebServices']['https']) ? 
                        "https://" : "http://" 
                        .$host.":".$port."/symws/";

        $this->sessionToken = isset($_SESSION['symws']['sessionToken']) ? 
            $_SESSION['symws']['sessionToken'] : '';
        
        try {
            $headerbody = array("clientID" => $this->clientID);     

            $options = array("sessionToken" => $this->sessionToken, "trace" => "1");
  
            $header = new SoapHeader($this->WS_HEADER, "SdHeader", $headerbody);
 
            $this->standardService = @new SoapClient($this->BASE_URL
                .$this->STANDARD_WSDL, $options);           
            $this->standardService->__setSoapHeaders($header);

            $this->securityService = @new SoapClient($this->BASE_URL
                .$this->SECURITY_WSDL, $options);
            $this->securityService->__setSoapHeaders($header);
            
            $this->adminService = @new SoapClient($this->BASE_URL
                .$this->ADMIN_WSDL, $options);
            $this->adminService->__setSoapHeaders($header);

        } catch (SoapFault $e) {
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function getOfflineMode() 
    {
        return false;
    }

    /**
     * Protected support method for getting a list of libraries.
     *
     * @return array An associative array of library codes and descriptions.
     * @access protected
     */
    protected function getLibraries()
    {
        if (isset($_SESSION['symws']['policies']['libraries'])) {
            return $_SESSION['symws']['policies']['libraries'];
        }

        try {
            $libraryList = array();
            $libraries   = $this->adminService->lookupLibraryPolicyList();

            foreach ($libraries as $library) {
                foreach ($library as $libraryCode) {
                    $libraryList[$libraryCode->policyID] = 
                        $libraryCode->policyDescription;
                }
            }

            $_SESSION['symws']['policies']['libraries'] = $libraryList;
            return $libraryList;
        } catch (SoapFault $e) {
            return $e;
        } catch (Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Protected support method for getting a list of locations.
     *
     * @return array An associative array of location codes and descriptions.
     * @access protected
     */
    public function getLocations()
    {
        if (isset($_SESSION['symws']['policies']['locations'])) {
            return $_SESSION['symws']['policies']['locations'];
        }

        try {
            $locationList = array();
            $locations    = $this->adminService->lookupLocationPolicyList();

            foreach ($locations as $location) {
                foreach ($location as $locationCode) {
                    $locationList[$locationCode->policyID] = 
                        $locationCode->policyDescription;
                }
            }
    
            $_SESSION['symws']['policies']['locations'] = $locationList;
            return $locationList;
        } catch (SoapFault $e) {
            return new PEAR_Error($e->getMessage());
        } catch (Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Protected support method for getting a list of item types.
     *
     * @return array An associative array of item type codes and descriptions.
     * @access protected
     */
    public function getTypes()
    {
        if (isset($_SESSION['symws']['policies']['types'])) {
            return $_SESSION['symws']['policies']['types'];
        }

        try {
            $typesList = array();
            $types     = $this->adminService->lookupItemTypePolicyList();

            foreach ($types as $type) {
                foreach ($type as $typeCode) {
                    $typesList[$typeCode->policyID] = $typeCode->policyDescription;
                }
            }

            $_SESSION['symws']['policies']['types'] = $typesList;
            return $typesList;
        } catch (SoapFault $e) {
            return new PEAR_Error($e->getMessage());
        } catch (Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id     The record id to retrieve the holdings for
     * @param array  $patron Patron data
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber, duedate,
     * number, barcode; on failure, a PEAR_Error.
     * @access public
     */
    public function getHolding($id, $patron = false) 
    {
        try {
            $parentID    = null;
            $parentTitle = null;
            $locations   = $this->getLocations();
            $types       = $this->getTypes();
            $libraries   = $this->getLibraries();
            $holdingList = array();

            $options = array("titleID" => $id,
                             "includeAvailabilityInfo" => "true",
                             "includeCallNumberSummary" => "true",
                             "includeItemInfo" => "true",
                             "includeOrderInfo" => "true",
                             "includeOPACInfo" => "true",
                             "includeBoundTogether" => "true",
                             "includeMarcHoldings" => "true",
                             "marcEntryFilter" => "NONE");

            $result = $this->standardService->lookupTitleInfo($options);

            if (isset($_REQUEST["DEBUG"])) {
                print_r($result);
            }

            $is_holdable = $result->TitleInfo->TitleAvailabilityInfo->holdable;
            
            $holdings = $result->TitleInfo->CallInfo;

            $baseCallNumber = $result->TitleInfo->baseCallNumber;

            if ($result->TitleInfo->numberOfBoundwithLinks > 0) {
                $boundWidthHoldings = $result->TitleInfo->BoundwithLinkInfo;
                
                if (!is_array($boundWidthHoldings)) {
                    $boundWidthHoldings = array($boundWidthHoldings);
                }
                
                foreach ($boundWidthHoldings as $CallInfo) {
                    if ($CallInfo->linkedAsParent) {
                        $parentID         = $CallInfo->linkedTitle->titleID;
                        $parentTitle      = $CallInfo->linkedTitle->title;
                        $callNumberFilter = $CallInfo->callNumber;
                    }
                }
            }

            if ($parentID) {
                $options["titleID"] = $parentID;

                $result   = $this->standardService->lookupTitleInfo($options);
                $holdings = $result->TitleInfo->CallInfo;
            }
            
            if (!is_array($holdings)) {
                $holdings = array($holdings);
            }
            
            foreach ($holdings as $CallInfo) {
                $items = $CallInfo->ItemInfo;

                if (!is_array($items)) {
                    $items = array($items);
                }
                
                foreach ($items as $ItemKey=>$ItemInfo) {
                    $number = $ItemKey + 1;
                    if ($ItemInfo != null && $ItemInfo->itemID != null) {

                        if ($ItemInfo->chargeable != 1) {
                            $availability = false;
                            $addLink      = true;
                            $status       = "Checked Out";
                        } else {
                            $availability = true;
                            $addLink      = false;
                            $status       = "Available";
                        }

                        $duedate = isset($ItemInfo->dueDate) ? 
                            date("F j, Y", strtotime($ItemInfo->dueDate)) : null;
                        // recallDueDate
                        $reserve = isset($ItemInfo->reserveCollectionID) ? 
                            "Y" : "N";
                        
                        if (!isset($callNumberFilter) ||
                           ($CallInfo->callNumber == $callNumberFilter) || 
                           ($parentID == $id)) {

                            $showBaseCallNumber = 
                                $this->config['Behaviors']['showBaseCallNumber'];

                            $action = isset($_GET['action']) ? 
                                $_GET['action'] : 'Home';
                            $action = preg_replace('/[^\w]/', '', $action);

                            $callnumber = (($action == "JSON") && 
                                ($showBaseCallNumber == true)) ? 
                                $baseCallNumber : $CallInfo->callNumber;

                            // Handle item notes
                            $notes = array();

                            if (isset($ItemInfo->publicNote)) {
                                $notes[] = $ItemInfo->publicNote;
                            }

                            if (isset($ItemInfo->staffNote) && 
                                $this->config['Behaviors']['showStaffNotes']) {
                                $notes[] = $ItemInfo->staffNote;
                            }

                            $requests_placed = isset($ItemInfo->numberOfHolds) ? 
                                $ItemInfo->numberOfHolds : 0;

                            $transitSourceLibraryID = 
                                isset($ItemInfo->transitSourceLibraryID) ? 
                                $ItemInfo->transitSourceLibraryID : null;

                            $transitDestinationLibraryID = 
                                isset($ItemInfo->transitDestinationLibraryID) ? 
                                $ItemInfo->transitDestinationLibraryID : null;
                        
                            $transitReason = 
                                isset($ItemInfo->transitReason) ? 
                                $ItemInfo->transitReason : null;
                        
                            $transitDate = 
                                isset($ItemInfo->transitDate) ? 
                                $ItemInfo->transitDate : null;

                            // Add item to list of holdings
                            $holdingList[] = array(
                                'id' => $id,
                                'availability' => $availability,
                                'status' => $status,
                                'location' =>
                                    $locations[$ItemInfo->currentLocationID],
                                'reserve' => $reserve,
                                'callnumber' => $callnumber,
                                'duedate' => $duedate,
                                //'returnDate' => ,
                                'number' => $number,
                                'requests_placed' => $requests_placed,
                                'barcode' => $ItemInfo->itemID,
                                'notes' => $notes,
                                'summary' => array(),
                                'is_holdable' => $is_holdable,
                                'holdtype' => 'hold',
                                'addLink' => $addLink && $is_holdable,

                                'item_id' => $ItemInfo->itemID,

                                // The fields below are non-standard and 
                                // should be added to your holdings.tpl
                                // RecordDriver template to be utilized.
                                'library' => $libraries[$CallInfo->libraryID],
                                'material' => $types[$ItemInfo->itemTypeID],
                                'bound_with_id' => $parentID,
                                'bound_with_title' => $parentTitle,
                                'transit_source_library_id' => 
                                    $transitSourceLibraryID,
                                'transit_destination_library_id' => 
                                    $transitDestinationLibraryID,
                                'transit_reason' => $transitReason,
                                'transit_date' => $transitDate
                            );
                        }
                    }
                }
            }
            return $holdingList;
        } catch (Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber; on
     * failure, a PEAR_Error.
     * @access public
     */
    public function getStatus($id)
    {
        return $this->getHolding($id);
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $idList The array of record ids to retrieve the status for
     *
     * @return mixed        An array of getStatus() return values on success,
     * a PEAR_Error object otherwise.
     * @access public
     */
    public function getStatuses($idList)
    {
        $status = array();
        foreach ($idList as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }
    
    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @return mixed An array with the acquisitions data on success, PEAR_Error
     * on failure
     * @access public
     */
    public function getPurchaseHistory($id)
    {
        return array();
    }

    /**
     * Login Is Hidden
     *
     * This method can be used to hide VuFind's login options
     *
     * @return boolean true if login options should be hidden, false if not.
     * @access public
     */
    public function loginIsHidden()
    {
        if (isset($this->config['Behaviors']['showAccountLogin']) 
            && ($this->config['Behaviors']['showAccountLogin'] == false)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username The patron username
     * @param string $password The patron password
     *
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login, PEAR_Error on error.
     * @access public
     */
    public function patronLogin($username, $password)
    { 
        try {
            $options = array("login" => $username, 
                             "password" => $password);

            $login = $this->securityService->loginUser($options);

            $headerbody = array("clientID" => $this->clientID,
                                "sessionToken" => $login->sessionToken);

            $header = new SoapHeader($this->WS_HEADER, "SdHeader", $headerbody);
            
            $this->patronService = new SoapClient($this->BASE_URL.
                                    $this->PATRON_WSDL, 
                                    array("trace" => 1));
            
            $this->patronService->__setSoapHeaders($header);

            if (isset($login->sessionToken)) {

                $_SESSION['symws']['sessionToken'] = $login->sessionToken;

                $options = array("includePatronInfo" => "ALL",
                                 "includePatronAddressInfo" => "ACTIVE");
                
                $account = $this->patronService->lookupMyAccountInfo($options);
                
                list($lastname,$firstname) = explode(', ',
                    $account->patronInfo->displayName);

                $user = array('id' => $account->patronInfo->userID,
                              'firstname' => $firstname,
                              'lastname' => $lastname,
                              'cat_username' => $username,
                              'cat_password' => $password,
                              'email' => null,
                              'major' => null,
                              'college' => null);
                //print_r($_SESSION);
                return $user;
            } else {
                return null;
            }
        } catch (SoapFault $e) {
            return null;
        } catch (Exception $e) {
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

        foreach ($this->getLibraries() as $key=>$library) {
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
        
        foreach ($cancelDetails['details'] as $holdKey) {
            try {
                $options = array("holdKey" => $holdKey);
                $hold    = $this->patronService->cancelMyHold($options);
                
                $count++;
                $items[$holdKey] = array(
                        'success' => true, 'status' => "hold_cancel_success"
                    );
            } catch (SoapFault $e) {
                $items[$holdKey] = array(
                        'success' => false, 'status' => "hold_cancel_fail",
                        'sysMessage' => $e->getMessage()
                    );
            } catch (Exception $e) {
                 $items[$holdKey] = array(
                        'success' => false, 'status' => "hold_cancel_fail",
                        'sysMessage' => $e->getMessage()
                    );
            }
        }
        $result = array('count' => $count, 'items' => $items);
        return $result;
    }
    
    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's holds on success, PEAR_Error
     * otherwise.
     * @access public
     */
    public function getMyHolds($patron)
    {
        try {
            $options = array("includePatronHoldInfo" => "ACTIVE");
            $result  = $this->patronService->lookupMyAccountInfo($options);
            
            if (!property_exists($result, "patronHoldInfo")) {
                return null;
            }
            
            $holds = $result->patronHoldInfo;
            
            if (!is_array($holds)) {
                $holds = array($holds);
            }

            foreach ($holds as $hold) {
                $holdList[] = array('id' => $hold->titleKey,
                                    //'type' => ,
                                    'location' => $hold->pickupLibraryID,
                                    'reqnum' => $hold->holdKey,
                                    'expire' => date("F j, Y", 
                                        strtotime($hold->expiresDate)),
                                    'create' => date("F j, Y", 
                                        strtotime($hold->placedDate)),
                                    'position' => $hold->queuePosition,
                                    'available' => $hold->available,
                                    'item_id' => $hold->itemID,
                                    //'volume' => ,
                                    //'publication_year' => ,
                                    'title' => $hold->title);
            }
            return $holdList;
        } catch(SoapFault $e) {
            return null;
        } catch(Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    public function checkRequestIsValid()
    {
        return true;
    }
    
    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's fines on success, PEAR_Error
     * otherwise.
     * @access public
     */
    public function getMyFines($patron)
    {
        try {
            $fineList = array();

            $feeType = $this->config['Behaviors']['showFeeType'];

            $options = array("includeFeeInfo" => $feeType);

            $result = $this->patronService->lookupMyAccountInfo($options);

            if (isset($result->feeInfo)) {
                $feeInfo = $result->feeInfo;

                foreach ($feeInfo as $fee) {
                    $fineList[] = array('amount' => $fee->amount->_ * 100,
                                        'checkout' => 
                                            isset($fee->feeItemInfo->checkoutDate) ? 
                                            $fee->feeItemInfo->checkoutDate : null,
                                        'fine' => $fee->billReasonDescription,
                                        'balance' => $fee->amountOutstanding->_ 
                                            * 100,
                                        'createdate' => 
                                            isset($fee->feeItemInfo->dateBilled) ? 
                                            $fee->feeItemInfo->dateBilled : null,
                                        'duedate' => 
                                            isset($fee->feeItemInfo->dueDate) ? 
                                            $fee->feeItemInfo->dueDate : null,
                                        'id' => isset($fee->feeItemInfo->titleKey) ? 
                                            $fee->feeItemInfo->titleKey : null,
                                       );
                }
            }
           
            return $fineList;
        } catch (SoapFault $e) {
            echo $e->getMessage();
            return new PEAR_Error($e->getMessage());
        } catch(Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron The patron array
     *
     * @return mixed        Array of the patron's profile data on success,
     * PEAR_Error otherwise.
     * @access public
     */
    public function getMyProfile($patron)
    {
        try {
            $options = array("includePatronInfo"        => "ALL",
                             "includePatronAddressInfo" => "ACTIVE",
                             "includePatronStatusInfo"  => "ALL");
        
            $result = $this->patronService->lookupMyAccountInfo($options);

            $addressInfo = $result->patronAddressInfo->Address1Info;
            $address1    = $addressInfo[0]->addressValue;
            $address2    = $addressInfo[1]->addressValue;
            $zip         = $addressInfo[2]->addressValue;
            $phone       = $addressInfo[3]->addressValue;

            list($lastname,$firstname) = explode(', ', 
                                            $result->patronInfo->displayName);

            $profile = array('lastname'  => $lastname,
                             'firstname' => $firstname,
                             'address1'  => $address1,
                             'address2'  => $address2,
                             'zip'       => $zip,
                             'phone'     => $phone,
                             'group'     => $result->patronInfo->groupID);

            return $profile;
        } catch (Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's transactions on success,
     * PEAR_Error otherwise.
     * @access public
     */
    public function getMyTransactions($patron)
    {
        try {
            $options = array("includePatronCheckoutInfo" => "ALL");

            $result = $this->patronService->lookupMyAccountInfo($options);

            $transactions = $result->patronCheckoutInfo;

            if (empty($transactions)) {
                return null;
            }

            if (!is_array($transactions)) {
                $transactions = array($transactions);
            }

            foreach ($transactions as $transaction) {
                if ($transaction->unseenRenewalsRemaining > 0) {
                    $renewable = true;
                } else {
                    $renewable = false;
                }

                $transList[] = array(
                                'duedate' => date("F j, Y",
                                    strtotime($transaction->dueDate)),
                                'id' => $transaction->titleKey,
                                'barcode' => $transaction->itemID,
                                'renew' => $transaction->renewals,
                                'request' => $transaction->recallNoticesSent,
                                //'volume' => $transaction->copyNumber,
                                //'publication_year' => ,
                                'renewable' => $renewable,
                                //'message' => ,
                                'title' => $transaction->title,
                                'item_id' => $transaction->itemID);
            }
            return $transList;
        } catch (Exception $e) {
            return new PEAR_Error($e->getMessage());
        }
    }
    
    /**
     * Get Renew Details
     *
     * In order to renew an item, Symphony requires the patron details and an item
     * id. This function returns the item id as a string which is then used
     * as submitted form data in checkedOut.php. This value is then extracted by
     * the RenewMyItems function.
     *
     * @param array $checkOutDetails An array of item data
     *
     * @return string Data for use in a form field
     */
    public function getRenewDetails($checkOutDetails)
    {
        $renewDetails = $checkOutDetails['barcode'];
        return $renewDetails;
    }

    /**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items.  The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
     * @return array              An array of renewal information keyed by item ID
     */
    public function renewMyItems($renewDetails)
    {
        $count = 0;
        $items = array();

        foreach ($renewDetails['details'] as $barcode) {
            try {
                $options = array("itemID" => $barcode);
                $renewal = $this->patronService->renewMyCheckout($options);
                $count++;
                $details[$barcode] = array('success' => true,
                                           'new_date' => date("j-M-y", 
                                              strtotime($renewal->dueDate)),
                                           'new_time' =>date("g:i a",
                                              strtotime($renewal->dueDate)),
                                           'item_id' => $renewal->itemID,
                                           'sysMessage' => $renewal->message);
            } catch (SoapFault $e) {
                $details[$barcode] = array('success' => false,
                                           'new_date' => false,
                                           'new_time' => false,
                                           'sysMessage' => 
                                              'We could not renew this item: ' . 
                                              $e->getMessage());
            } catch (Exception $e) {
                $details[$barcode] = array('success' => false,
                                           'new_date' => false,
                                           'new_time' => false,
                                           'sysMessage' => 
                                              'We could not renew this item: ' . 
                                              $e->getMessage());
            }
        }

        $result = array('details' => $details);
        return $result;
    }

    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item
     *
     * @param array $holdDetails An array of item and patron data
     *
     * @return array  An array of data on the request including
     * whether or not it was successful and a system message (if available)
     * @access public
     */
    public function placeHold($holdDetails)
    {
        try {
            $options = array();

            if ($holdDetails['item_id'] != null) {
                $options["itemID"] = $holdDetails['item_id'];
            }

            if ($holdDetails['id'] != null) {
                $options["titleID"] = $holdDetails['id'];
            }

            if ($holdDetails['pickUpLocation'] != null) {
                $options["pickupLibraryID"] = $holdDetails['pickUpLocation'];
            }

            if ($holdDetails['requiredBy'] != null) {
                $options["expiresDate"] = $holdDetails['requiredBy'];
            }

            $hold = $this->patronService->createMyHold($options);

            $result = array(
                        'success' => true,
                        'sysMessage' => 'Your hold has been placed.',
                        );
            return $result;
        } catch (SoapFault $e) {
            $result = array(
                        'success' => false,
                        'sysMessage' =>
                            'We could not place the hold: ' . $e->getMessage()
                        );
            return $result;
        }
    }

    /**
     * Find Reserves
     *
     * Obtain information on course reserves.
     *
     * This version of findReserves was contributed by Matthew Hooper and includes
     * support for electronic reserves (though eReserve support is still a work in
     * progress).
     *
     * @param string $course ID from getCourses (empty string to match all)
     * @param string $inst   ID from getInstructors (empty string to match all)
     * @param string $dept   ID from getDepartments (empty string to match all)
     *
     * @return mixed An array of associative arrays representing reserve items (or a
     * PEAR_Error object if there is a problem)
     * @access public
     */
    public function findReserves($course = null, $inst = null, $dept = null)
    {
        if ($course) {
            $params = array(
                'browseType' => 'COURSE_ID',
                'browseValue' => $course,
                'courseID' => $course);
        } elseif ($inst) {
            $params = array(
                'browseType' => "USER_NAME",
                'browseValue' => $int,
                'userID' => $inst,
                //'browseDirection' => 'FORWARD',
                'courseID' => 'RDG518',
               // 'listID' => 'test',
               // 'firstLineNumber' => 1,
                //'lastEntryID' => ''
            );
        } elseif ($dept) {
            $params = array(
                'COURSE_NAME' => $dept
            );
        } else {
            $params = array(
                'query' => 'reserves', 'course' => '', 'instructor' => '',
                'desk' => ''
            );
        }

        $items = array();

        try {
            $this->reserveService = @new SoapClient($this->BASE_URL
                .$this->RESERVE_WSDL, $options);
            $this->reserveService->__setSoapHeaders($header);

            // $reserves = $this->reserveService->browseReserve($params);
            //$reserves = $this->reserveService->listReservePaging($params);
            $reserves = $this->reserveService->lookupReserve($params);

            //print_r($reserves);

            /*
            if ($bib_id && (empty($instructorId) || $instructorId == $instructor_id)
                && (empty($courseId) || $courseId == $course_id)
                && (empty($departmentId) || $departmentId == $dept_id)
            ) {
                $items[] = array (
                    'BIB_ID' => $bib_id
                );
            }
            */
            return $items;
        } catch (SoapFault $e) {
            return new PEAR_Error('Could not find reserves: ' . 
                $e->getMessage());
        } catch (Exception $e) {
            return new PEAR_Error('Could not find reserves: ' . 
                $e->getMessage());
        }

        
    }

    /**
     * Get Instructors
     *
     * Obtain a list of instructors for use in limiting the reserves list.
     *
     * @return array An associative array with key = ID, value = name.
     * @access public
     */
    public function getInstructors()
    {
        try {
            $this->reserveService = @new SoapClient($this->BASE_URL
                .$this->RESERVE_WSDL, $options);
            $this->reserveService->__setSoapHeaders($header);

            $users = array();

            $reserveOptions = array("browseType" => "USER_NAME");
            $reserves       = $this->reserveService->browseReserve($reserveOptions);

            
            //print_r($reserves);
            foreach ($reserves->reserveInfo as $reserve) {
                $users[$reserve->userID] = $reserve->userDisplayName;
            }

            asort($users);

            return $users;
        } catch (SoapFault $e) {
            return null;        
        }
    }

    /**
     * Get Courses
     *
     * Obtain a list of courses for use in limiting the reserves list.
     *
     * @return array An associative array with key = ID, value = name.
     * @access public
     */
    public function getCourses()
    {
        try {
            $this->reserveService = @new SoapClient($this->BASE_URL
                .$this->RESERVE_WSDL, $options);
            $this->reserveService->__setSoapHeaders($header);

            $courses = array();

            $reserveOptions = array("browseType" => "COURSE_NAME");
            $reserves       = $this->reserveService->browseReserve($reserveOptions);
            
            foreach ($reserves->reserveInfo as $reserve) {
                $courses[$reserve->courseID] = $reserve->courseID;
            }
            
            asort($courses);

            return $courses;
        } catch (SoapFault $e) {
            return null;
        }
    }

    /**
     * Get Departments
     *
     * Obtain a list of departments for use in limiting the reserves list.
     *
     * @return array An associative array with key = dept. ID, value = dept. name.
     * @access public
     */
    public function getDepartments()
    {
        try {
            $this->reserveService = @new SoapClient($this->BASE_URL
                .$this->RESERVE_WSDL, $options);
            $this->reserveService->__setSoapHeaders($header);

            $depts = array();

            $reserveOptions = array("browseType" => "COURSE_NAME");
            $reserves       = $this->reserveService->browseReserve($reserveOptions);

            foreach ($reserves->reserveInfo as $reserve) {
                $depts[$reserve->courseName] = $reserve->courseName;
            }

            asort($depts);
            
            return $depts;
        } catch (SoapFault $e) {
            return null;
        }
    }
}
?>
