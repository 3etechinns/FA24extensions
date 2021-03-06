<?php
/**********************************************
Author: Tom Moulton
Name: osCommerce order import to Sales Order
Free software under GNU GPL
***********************************************/
$page_security = 'SA_OSCORDERS';
$path_to_root  = "../..";

include($path_to_root . "/includes/session.inc");
add_access_extensions();

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/sales/includes/db/branches_db.inc");
include_once($path_to_root . "/sales/includes/db/customers_db.inc");
include_once($path_to_root . "/sales/includes/db/sales_order_db.inc");
include_once($path_to_root . "/sales/includes/cart_class.inc");
include_once($path_to_root . "/sales/includes/ui/sales_order_ui.inc");
include_once($path_to_root . "/inventory/includes/db/items_prices_db.inc");
include_once($path_to_root . "/taxes/db/item_tax_types_db.inc");
include_once($path_to_root . "/includes/date_functions.inc");

/*
SELECT c.customers_id, CONCAT(c.customers_firstname, ' ', c.customers_lastname) , b.entry_street_address, b.entry_suburb, b.entry_city, b.entry_zone_id, b.entry_state, b.entry_postcode, b.entry_country_id, c.customers_telephone, c.customers_email_address FROM customers c left join `customers_info` i on c.customers_id = i.customers_info_id left join address_book b on c.customers_default_address_id = b.address_book_id

SELECT `customers_id` FROM `customers` order by `customers_id` desc LIMIT 0,1
SELECT `customers_id` FROM `customers` order by `customers_id` asc LIMIT 0,1

SELECT products_model, products_price FROM products WHERE products_status = 1
*/

function osc_connect() {
    global $db, $one_database, $dbHost, $dbUser, $dbPassword, $dbName;
    
    $osc = false;
    if ($one_database) $osc = $db;
    else $osc = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

    if (!$osc) display_notification("Failed to connect osCommerce Database");

    return $osc;
}

function osc_dbQuery($sql, $multirow = false) {
    global $osc;

    $sql = trim($sql);
    $sql_mod = in_array(substr(strtoupper($sql),0,6), array('INSERT', 'UPDATE', 'DELETE'));
    $result = mysqli_query($osc, $sql);
    if (!$result)
        display_notification($sql);
    if ($multirow || $sql_mod)
        return $result;
    $data = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    return $data;
}

function osc_insert_id() {
    global $osc;
	return mysqli_insert_id($osc);
}

function osc_escape($value = "", $nullify = false) {
    global $osc;
    
    $value = @html_entity_decode($value, ENT_QUOTES, $_SESSION['language']->encoding);
    $value = html_specials_encode($value);

      //reset default if second parameter is skipped
    $nullify = ($nullify === null) ? (false) : ($nullify);

      //check for null/unset/empty strings
    if ((!isset($value)) || (is_null($value)) || ($value === "")) {
        $value = ($nullify) ? ("NULL") : ("''");
    } else {
        if (is_string($value)) {
            $value = "'" . mysqli_real_escape_string($osc, $value) . "'";
              //value is a string and should be quoted; 
        } else if (!is_numeric($value)) {
            //value is not a string nor numeric
            display_error("ERROR: incorrect data type send to sql query");
            echo '<br><br>';
            exit();
        }
    }
    return $value;
}

function not_null($str) {
    if ($str != '' && $str != NULL) return 1;
    return 0;
}

function osc_new_orders($date, $statusId) {
    display_notification($date);
    $sql  = "SELECT COUNT(*) as count FROM orders WHERE date_purchased > ".osc_escape($date);
    $sql .= " AND orders_id NOT IN (SELECT orders_id FROM orders_status_history oh WHERE LOCATE('Imported into FA', comments) != 0)";
    if ($statusId != "")
        $sql .= " AND orders_status != " . $statusId;
    $count = osc_dbQuery($sql);
    return $count['count'];
}

function osc_get_zone_code($zone_name) {
    $sql  = "SELECT zone_code from zones where zone_name=".osc_escape($zone_name);
    $zone = osc_dbQuery($sql);
    $id   = $zone['zone_code'];
    if (!not_null($id)) $id = $zone_name;
    return $id;
}

function osc_get_zone_code_from_id($zone_id) {
    $sql  = "SELECT zone_code from zones where zone_id=".osc_escape($zone_id);
    $zone = osc_dbQuery($sql);
    $id   = $zone['zone_code'];
    return $id;
}

function osc_get_zone_name_from_id($zone_id) {
    $sql  = "SELECT zone_name from zones where zone_id=".osc_escape($zone_id);
    $zone = osc_dbQuery($sql);
    $id   = $zone['zone_name'];
    return $id;
}

function osc_get_country($country_id) {
    $sql     = "SELECT countries_name from countries where countries_id=".osc_escape($country_id);
    $country = osc_dbQuery($sql);
    $id      = $country['countries_name'];
    return $id;
}

function osc_address_format($data, $pre) {
    $company = $data[$pre . 'company'];
    $name = $data[$pre . 'name'];
    $street_address = $data[$pre . 'street_address'];
    $suburb         = $data[$pre . 'suburb'];
    $city           = $data[$pre . 'city'];
    $postcode       = $data[$pre . 'postcode'];
    // $state = $data[$pre . 'state'];
    if (not_null($data[$pre . 'state'])) $state = $data[$pre . 'state'];
    else if (not_null($data[$pre . 'zone_id'])) $state = osc_get_zone_code_from_id( $data[$pre . 'zone_id']);
    if (!empty($data[$pre . 'country_id'])) $country = osc_get_country($data[$pre . 'country_id']);
    else $country = $data[$pre . 'country'];

    $ret = '';
    if (not_null($company)) $ret .= $company . "\n";
    $ret .= $name . "\n" . $street_address . "\n";
    if (not_null($suburb))   $ret .= $suburb . "\n";
    if (not_null($city))     $ret .= $city;
    if (not_null($state))    $ret .= ", " . osc_get_zone_code($state);
    if (not_null($postcode)) $ret .= " " . $postcode . "\n";
    else $ret .=  "\n";
    if (not_null($country))  $ret .= $country . "\n";
    return $ret;
}

function get_tax_group_from_zone_id($zone_id, $def_tax_group_id) {
    $tax_group = osc_get_zone_name_from_id($zone_id);
    $taxgid    = "";
    if ($tax_group != "") {
        $sql    = "SELECT id from ".TB_PREF."tax_groups WHERE name=".db_escape($tax_group);
        $result = db_query($sql, "Non Taxable Group");
        $row    = db_fetch_row($result);
        if ($row) $taxgid = $row[0];
    }
    if ($taxgid == "") $taxgid = $def_tax_group_id;
    return $taxgid;
}

function check_stock_id($stock_id) {
    $sql    = "SELECT * FROM ".TB_PREF."stock_master where stock_id = " . db_escape($stock_id);
    $result = db_query($sql, "Can not look up stock_id");
    $row    = db_fetch_row($result);
    if (!$row[0]) return 0;
    return 1;
}

function get_item_category_by_name($name) {
    $sql    = "SELECT * FROM ".TB_PREF."stock_category WHERE description=".db_escape($name);
    $result = db_query($sql, "an item category could not be retrieved");
    return db_fetch($result);
}

function get_item_tax_type_by_name($name) {
    $sql = "SELECT * FROM ".TB_PREF."item_tax_types WHERE name=".db_escape($name);
    $result = db_query($sql, "could not get item tax type");
    return db_fetch($result);
}

function get_sales_point_by_name($name)
{
    $sql = "SELECT pos.*, loc.location_name, acc.bank_account_name FROM "
            .TB_PREF."sales_pos as pos
            LEFT JOIN ".TB_PREF."locations as loc on pos.pos_location=loc.loc_code
            LEFT JOIN ".TB_PREF."bank_accounts as acc on pos.pos_account=acc.id
            WHERE pos.pos_name=".db_escape($name);

    $result = db_query($sql, "could not get POS definition");

    return db_fetch($result);
}

function addItemToFA($osc_id, $products_name, $cat, $tax_type_id, $mb_flag, $products_price, $products_status) {
    $inactive = ($products_status == 0 ? 1 : 0);
    $sql      = "SELECT stock_id FROM ".TB_PREF."stock_master WHERE stock_id=".db_escape($osc_id);
    $result   = db_query($sql,"item could not be retreived");
    $row      = db_fetch_row($result);
    if (!$row) {
        $sql = "INSERT INTO ".TB_PREF."stock_master (stock_id, description, long_description, category_id,
                tax_type_id, units, mb_flag, sales_account, inventory_account, cogs_account,
                adjustment_account, wip_account, dimension_id, dimension2_id, inactive)
                VALUES ('$osc_id', " . db_escape($products_name) . ", '',
                '$cat', '$tax_type_id', '{$_POST['units']}', '$mb_flag',
                '{$_POST['sales_account']}', '{$_POST['inventory_account']}', '{$_POST['cogs_account']}',
                '{$_POST['adjustment_account']}', '{$_POST['wip_account']}', '{$_POST['dimension_id']}', '{$_POST['dimension2_id']}', '$inactive')";

        db_query($sql, "The item could not be added");
        $sql = "INSERT INTO ".TB_PREF."loc_stock (loc_code, stock_id) VALUES ('{$_POST['default_location']}', ".db_escape($osc_id).")";

        db_query($sql, "The item locstock could not be added");
        add_item_price($osc_id, $_POST['sales_type'], $_POST['currency'], $products_price);
        display_notification("Insert $osc_id " . $products_name);
    } else {
        // FrontAccounting is considered the inventory master
        // so once an osCommerce item is imported into FA,
        // update is limited to fields controlled by OSC
        // ignoring price and inventory quantity.
        // Note that Update Prices and Update Inventory
        // will overwrite these fields in osCommerce, so it
        // it pointless to change them in osCommerce.
        // Conversely, product name, category, tax class are controlled
        // by osCommerce so it is pointless to change them
        // in FA, as they will be overwritten.
        $sql = "UPDATE ".TB_PREF."stock_master SET description=" . db_escape($products_name) .", category_id='$cat', tax_type_id='$tax_type_id'
                WHERE stock_id=" . db_escape($osc_id);

        db_query($sql, "The item could not be updated");
        display_notification("Update $osc_id $products_name");
    }

    $sql    = "SELECT id from ".TB_PREF."item_codes WHERE item_code=".db_escape($osc_id)." AND stock_id = ".db_escape($osc_id);
    $result = db_query($sql, "item code could not be retreived");
    $row    = db_fetch_row($result);
    if (!$row) add_item_code($osc_id, $osc_id, $products_name, $cat, 1);
    else update_item_code($row[0], $osc_id, $osc_id, $products_name, $cat, 1);
}

function sales_service_items_list_row($label, $name, $selected_id=null, $all_option=false, $submit_on_change=false) {
    echo "<tr>";
    if ($label != null)
        echo "<td class='label'>$label</td>\n";
    echo "<td>";
    echo sales_items_list($name, $selected_id, $all_option, $submit_on_change,
            'local', array('where'=>array("mb_flag='D'"), 'cells'=>false, 'editable' => false));
    echo "</td></tr>";
}


// error_reporting(E_ALL);
// ini_set("display_errors", "on");

global $db; // Allow access to the FA database connection
$debug_sql = 0;

global $db_connections;
$cur_prefix = $db_connections[$_SESSION["wa_current_user"]->cur_con]['tbpref'];

check_db_has_sales_areas("You must first define atleast one Sales Area");

$sql          = "SHOW TABLES";
$result       = db_query($sql, "could not show tables");
$found        = 0;
$one_database = 0; // Use one DB, auto-detect below
while (($row = db_fetch_row($result))) {
    if ($row[0] == $cur_prefix."oscommerce") $found = 1;
    if (stripos($row[0], 'orders_status_history') !== false) $one_database = 1;
}

$dbHost           = "";
$dbUser           = "";
$dbPassword       = "";
$dbName           = "";
$oscId            = "products_model";
$oscPrefix        = "";
$lastcid          = 0;
$lastdate         = "";
$defaultTaxGroup  = 0;
$destCust         = 0;
$invCust          = 0;
$statusId         = 0;

$db_Host          = "";
$db_User          = "";
$db_Password      = "";
$db_Name          = "";
$osc_Id           = "products_model";
$osc_Prefix       = "";
$last_cid         = 0;
$last_date        = "";
$default_TaxGroup = 0;

$min_cid  = 0;
$max_cid  = 0;
$min_date = "";
$max_date = "";
$min_iid  = 0;
$max_iid  = 0;
$order_count = 0;

if ($found) {
    // Get Host Name
    $sql     = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'myhost'";
    $result  = db_query($sql, "could not get host name");
    $row     = db_fetch_row($result);
    $db_Host = $row[1];

    // Get User Name
    $sql     = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'myuser'";
    $result  = db_query($sql, "could not get user name");
    $row     = db_fetch_row($result);
    $db_User = $row[1];

    // Get Password
    $sql         = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'mypassword'";
    $result      = db_query($sql, "could not get password");
    $row         = db_fetch_row($result);
    $db_Password = $row[1];

    // Get DB Name
    $sql     = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'myname'";
    $result  = db_query($sql, "could not get DB name");
    $row     = db_fetch_row($result);
    $db_Name = $row[1];

    // Get item prefix
    $sql        = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'osc_prefix'";
    $result     = db_query($sql, "could not get osc_prefix");
    $row        = db_fetch_row($result);
    $osc_Prefix = $row[1];

    // Get item prefix
    $sql    = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'osc_id'";
    $result = db_query($sql, "could not get osc_id");
    $row    = db_fetch_row($result);
    $osc_Id = $row[1];

    // Get last cID imported
    $sql    = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'lastcid'";
    $result = db_query($sql, "could not get DB name");
    $row    = db_fetch_row($result);
    if (!$row) {
        $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('lastcid', 0)";
        db_query($sql, "add lastcid");
        $last_cid = 0;
    } else $last_cid = $row[1];

    // Get last oID imported
    $sql = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'lastdate'";
    $result = db_query($sql, "could not get DB name");
    $row    = db_fetch_row($result);
    if (!$row) {
        $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('lastdate', 0)";
        db_query($sql, "add lastdate");
        $last_date = "";
    } else $last_date = $row[1];

    // Get Default Tax Group
    $sql              = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'taxgroup'";
    $result           = db_query($sql, "could not get taxgroup");
    $row              = db_fetch_row($result);
    $default_TaxGroup = $row[1];

    // Get destination customer
    $sql        = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'destCust'";
    $result     = db_query($sql, "could not get destCust");
    $row        = db_fetch_row($result);
    $destCust  = $row[1];

    // Get inventory customer
    $sql        = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'invCust'";
    $result     = db_query($sql, "could not get invtCust");
    $row        = db_fetch_row($result);
    $invCust  = $row[1];

    // Get status Id
    $sql        = "SELECT * FROM ".TB_PREF."oscommerce WHERE name = 'statusId'";
    $result     = db_query($sql, "could not get statusId");
    $row        = db_fetch_row($result);
    $statusId  = $row[1];
}

$num_price_errors = -1;
$num_qty_errors = -1;

$action = 'summary';
if (isset($_GET['action']) && $found) $action = $_GET['action'];
if (!$found) $action = 'show';

if ($action == 'c_import') {
    if (!check_num('credit_limit', 0)) {
        display_error(_("The credit limit must be numeric and not less than zero."));
        $action = 'cimport';
    }
}

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    // Create Table
    if ($action == 'create') {
        $sql = "DROP TABLE IF EXISTS ".TB_PREF."oscommerce";
        db_query($sql, "Error dropping table");
        $sql = "CREATE TABLE ".TB_PREF."oscommerce ( `name` char(15) NOT NULL default '', " .
               " `value` varchar(100) NOT NULL default '', PRIMARY KEY  (`name`)) ENGINE=MyISAM";
        db_query($sql, "Error creating table");
        header("Location: osCommerce.php?action=show");
    }

    if ($action == 'update') {
        if (isset($_POST['dbHost']))     $dbHost          = $_POST['dbHost'];
        if (isset($_POST['dbUser']))     $dbUser          = $_POST['dbUser'];
        if (isset($_POST['dbPassword'])) $dbPassword      = $_POST['dbPassword'];
        if (isset($_POST['dbName']))     $dbName          = $_POST['dbName'];
        if (isset($_POST['oscId']))      $oscId           = $_POST['oscId'];
        if (isset($_POST['oscPrefix']))  $oscPrefix       = $_POST['oscPrefix'];
        if (isset($_POST['taxgroup']))   $defaultTaxGroup = $_POST['taxgroup'];

        if ($dbHost != $db_Host) { // It changed
            if ($dbHost == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'myhost'";
            else if ($db_Host == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('myhost', ".db_escape($dbHost).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($dbHost)." WHERE name = 'myhost'";
            db_query($sql, "Update 'myhost'");
        }

        if ($dbUser != $db_User) { // It changed
            if ($dbUser == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'myuser'";
            else if ($db_User == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('myuser', ".db_escape($dbUser).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($dbUser)." WHERE name = 'myuser'";
            db_query($sql, "Update 'myuser'");
        }

        if ($dbPassword != $db_Password) { // It changed
            if ($dbPassword == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'mypassword'";
            else if ($db_Password == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('mypassword', ".db_escape($dbPassword).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($dbPassword)." WHERE name = 'mypassword'";
            db_query($sql, "Update 'mypassword'");
        }

        if ($dbName != $db_Name) { // It changed
            if ($dbName == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'myname'";
            else if ($db_Name == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('myname', ".db_escape($dbName).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($dbName)." WHERE name = 'myname'";
            db_query($sql, "Update 'myname'");
        }

        if ($oscId != $osc_Id) { // It changed
            if ($oscId == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'osc_id'";
            else if ($osc_Id == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('osc_id', ".db_escape($oscId).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($oscId)." WHERE name = 'osc_id'";
            db_query($sql, "Update 'osc_id'");
        }

        if ($oscPrefix != $osc_Prefix) { // It changed
            if ($oscPrefix == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'osc_prefix'";
            else if ($osc_Prefix == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('osc_prefix', ".db_escape($oscPrefix).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($oscPrefix)." WHERE name = 'osc_prefix'";
            db_query($sql, "Update 'osc_prefix'");
        }

        if ($lastcid != $last_cid) { // It changed
            if ($lastcid == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'lastcid'";
            else if ($last_cid == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('lastcid', ".db_escape($lastcid).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($lastcid)." WHERE name = 'lastcid'";
            db_query($sql, "Update 'lastcid'");
        }

        if ($lastdate != $last_date) { // It changed
            if ($lastdate == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'lastdate'";
            else if ($last_date == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('lastdate', ".db_escape($lastdate).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($lastdate)." WHERE name = 'lastdate'";
            db_query($sql, "Update 'lastdate'");
        }

        if ($defaultTaxGroup != $default_TaxGroup) { // It changed
            if ($defaultTaxGroup == '') $sql = "DELETE FROM ".TB_PREF."oscommerce WHERE name = 'taxgroup'";
            else if ($default_TaxGroup == '') $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('taxgroup', ".db_escape($defaultTaxGroup).")";
            else $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($defaultTaxGroup)." WHERE name = 'taxgroup'";
            db_query($sql, "Update 'defaultTaxGroup'");
            header("Location: osCommerce.php?action=summary");
        }

    } else {
        $dbHost          = $db_Host;
        $dbUser          = $db_User;
        $dbPassword      = $db_Password;
        $dbName          = $db_Name;
        $oscId           = $osc_Id;
        $oscPrefix       = $osc_Prefix;
        $lastcid         = $last_cid;
        $lastdate        = $last_date;
        $defaultTaxGroup = $default_TaxGroup;
    }

    if ( in_array($action, array('c_import', 'o_import', 'p_check', 'p_update', 'i_import', 'i_check', 'i_update')) && ($osc = osc_connect()) ) {

        if ($action == 'c_import') {
            if (!check_num('credit_limit', 0)) {
                display_error(_("The credit limit must be numeric and not less than zero."));
            }
        
            $min_cid = 0;
            $max_cid = 0;
            if (isset($_POST['min_cid'])) $min_cid = $_POST['min_cid'];
            if (isset($_POST['max_cid'])) $max_cid = $_POST['max_cid'];

            $sql = "SELECT * FROM customers c LEFT JOIN address_book b on c.customers_default_address_id = b.address_book_id where c.customers_id  >= ".osc_escape($min_cid)." AND c.customers_id <= ".osc_escape($max_cid);
            $customers = osc_dbQuery($sql, true);
            display_notification("Found " . db_num_rows($customers) . " new customers");
            $i = $j = 0;
            while ($cust = mysqli_fetch_assoc($customers)) {
                $email     = $cust['customers_email_address'];
                $cust['name'] = $name      = $cust['customers_firstname'] . ' ' . $cust['customers_lastname'];
                $contact   = $cust['entry_firstname'] . ' ' . $cust['entry_lastname'];
                $addr      = osc_address_format($cust, 'entry_');
                $tax_id    = '';
                $phone     = $cust['customers_telephone'];
                $fax       = $cust['customers_fax'];
                $area_code = $_POST['area'];
                $currency  = $_POST['currency'];

                // id; name; address1; address2; address3; address4; area; phone; fax; email; contact; tax_id; currency; tax_group

                $taxgid = get_tax_group_from_zone_id($cust['entry_zone_id'], $_POST['tax_group_id']);

                $sql    = "SELECT debtor_no,name FROM ".TB_PREF."debtors_master WHERE name=".db_escape($name);
                $result = db_query($sql, "customer could not be retrieved");
                $row    = db_fetch_assoc($result);

                if (!$row) {
                    add_customer($name, $name, $addr, $tax_id, $currency, $_POST['dimension_id'], $_POST['dimension2_id'], 1, $_POST['payment_terms'], 0, 0, input_num('credit_limit'), $_POST['sales_type'], NULL);
                    $id = db_insert_id();
                    if ($debug_sql) display_notification("INSERT DM " . $sql);
                    db_query($sql, "The customer could not be added");
                    add_branch($id, $name, $name, $addr, $_POST['salesman'], $area_code, $taxgid, $_POST['sales_account'], $_POST['sales_discount_account'], $_POST['receivables_account'], $_POST['payment_discount_account'], $_POST['default_location'], $addr, 0, 0, 1, NULL);
                    if ($debug_sql) display_notification("INSERT BR " . $sql);
                    display_notification("Added New Customer $name");

                    $i++;
                } else {
                    update_customer($row['debtor_no'], $name, $name, $addr, $tax_id, $currency, $_POST['dimension_id'], $_POST['dimension2_id'], 1, $_POST['payment_terms'], 0, 0, input_num('credit_limit'), $_POST['sales_type'], NULL);
                    if ($debug_sql) display_notification("UPDATE DM " . $sql);
                    display_notification("Updated Customer $name");
                    $j++;
                }
                if ((int) $cust['customers_id'] > $lastcid) {
                    $sql = "UPDATE ".TB_PREF."oscommerce SET value = ".db_escape($cust['customers_id'])." WHERE `name` = 'lastcid'";
                    db_query($sql, "Update 'lastcid'");
                }
            }
            mysqli_free_result($customers);
            display_notification("$i customer posts created, $j customer posts updated.");
        }

        if ($action == 'o_import') { // Import Order specified by oID
            $first_date = date2sql($_POST['first_date']);
            $end_date  = date2sql($_POST['last_date']);

            $destCust        = $_POST['destCust'];
            $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('destCust', ".db_escape($destCust).") ON DUPLICATE KEY UPDATE name='destCust', value=".db_escape($destCust);
            db_query($sql, "Update 'destCust'");

            $statusId        = $_POST['statusId'];
            $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('statusId', ".db_escape($statusId).") ON DUPLICATE KEY UPDATE name='statusId', value=".db_escape($statusId);
            db_query($sql, "Update 'statusId'");

            $customer = null;
            $errors = (int) $_POST['errors'];
            $sql = "SELECT * FROM orders WHERE date_purchased BETWEEN ".osc_escape($first_date ." 00:00:00") . " AND " . osc_escape($end_date . " 23:59:59");
            if ($statusId != "")
                $sql .= " AND orders_status != " . $statusId;
            $sql .= " AND orders_id not in (select orders_id from orders_status_history oh where LOCATE('Imported into FA', comments) != 0) group by orders_id";
            $oid_result = osc_dbQuery($sql, true);
            display_notification("Found " . mysqli_num_rows($oid_result) . " New Orders");

            while ($order = mysqli_fetch_assoc($oid_result)) {
                $oID            = $order['orders_id'];
                $date_purchased = $order['date_purchased'];

                $sql = "SELECT * FROM orders_total WHERE orders_id = ".osc_escape($oID) . " ORDER BY sort_order";
                $total_shipping = 0;
                $total_total    = 0;
                $total_discount = 0;
                $total_subtotal = 0;
                $found_subtotal = false;
                $total_result   = osc_dbQuery($sql, true);
                while ($total = mysqli_fetch_assoc($total_result)) {
                    switch ($total['class']) {
                        case 'ot_shipping' :
                            $total_shipping = $total['value'];
                            break;
                        case 'ot_total' :
                            $total_total = round($total['value'],2);
                            break;
                        case 'ot_subtotal' :
                            $total_subtotal += $total['value'];
                            $found_subtotal = true;
                            break;
                        case 'ot_tax' :
                            break;
                        case 'ot_discount' :
                        default:
                            if ($found_subtotal == false)
                                $total_subtotal -= $total['value'];
                            $total_discount -= $total['value'];
                            break;
                    }
                }
                mysqli_free_result($total_result);

                $sql      = "SELECT comments FROM orders_status_history WHERE orders_id = ".osc_escape($oID);
                $result   = osc_dbQuery($sql, true);

                $comments = "";
                while ($row = mysqli_fetch_assoc($result)) {
                    if (not_null($row['comments'])) $comments .= $row['comments'] . "\n";
                }
                mysqli_free_result($result);

                if (empty($order['customers_name']))
                    $customers_name = $order['customers_company'];
                else
                    $customers_name = $order['customers_name'];
                $sql    = "SELECT * FROM ".TB_PREF."debtors_master WHERE `name` = ".db_escape($customers_name);
                $result = db_query($sql, "Could not find customer by name");
                if (db_num_rows($result) == 0) {
                    if ($destCust == 0) {
                        display_notification("Customer " . $customers_name . " not found");
                        break;
                    }
                    $customer = db_fetch_assoc($result);
                    $addr     = osc_address_format($order, 'delivery_');
                    $taxgid   = get_tax_group_from_zone_id($order['delivery_state'], $defaultTaxGroup);
                    $sql      = "SELECT * FROM ".TB_PREF."cust_branch WHERE debtor_no = ".db_escape($customer['debtor_no'])." AND br_address = ".db_escape($addr);
                    if ($debug_sql) display_notification("Find BR " . $sql);
                    $result = db_query($sql, "could not find customer branch");
                    if (db_num_rows($result) == 0) {
                        if ($debug_sql) display_notification("New Branch");
                        $debtor_no = $customer['debtor_no'];
                        $sql       = "SELECT * FROM ".TB_PREF."cust_branch WHERE debtor_no = ".db_escape($debtor_no);
                        if ($debug_sql) display_notification("Find BR * " . $sql);
                        $result     = db_query($sql, "could not find any customer branch");
                        $old_branch = db_fetch_assoc($result);
                        if ($debug_sql) print_r($old_branch);
                        add_branch($debtor_no, $old_branch['br_name'], $old_branch['branch_ref'], $addr, $old_branch['salesman'], $old_branch['area'], $taxgid, $old_branch['sales_account'], $old_branch['sales_discount_account'], $old_branch['receivables_account'], $old_branch['payment_discount_account'], $old_branch['default_location'], $addr, 0, 0, 1, $old_branch['notes']);
                        $id  = db_insert_id();
                        $sql = "SELECT *, t.name AS tax_group_name FROM ".TB_PREF."cust_branch LEFT JOIN ".TB_PREF."tax_groups t ON tax_group_id=t.id WHERE branch_code = ".db_escape($id);
                        if ($debug_sql) display_notification("Get BR " . $sql);
                        $result = db_query($sql, "Could not load new branch");
                    } else {
                        $sql    = "SELECT * FROM ".TB_PREF."debtors_master WHERE debtor_no=".$destCust;
                        $result = db_query($sql, "Could not find customer by name");
                        if (db_num_rows($result) == 0) {
                            display_error("Customer id " . $destCust  . " not found");
                            display_error('Skipping order ' . $oID);
                            break;
                        }
                        $customer = db_fetch_assoc($result);
                        $debtor_no = $customer['debtor_no'];
                        $found = false;

                        foreach ( array ($order['delivery_city'], $order['delivery_state']) as $value ) {
                            $sql    = "SELECT *, t.name AS tax_group_name FROM ".TB_PREF."cust_branch LEFT JOIN ".TB_PREF."areas ON area_code=area LEFT JOIN ".TB_PREF."tax_groups t ON tax_group_id=t.id WHERE debtor_no = ".db_escape($debtor_no) . " AND description = " .db_escape($value);
                            $result = db_query($sql, "Could not load branch");
                            if (db_num_rows($result) != 0) {
                                $found = true;
                                break;
                    }
                }

                        if ($found == false) {
                            $sql    = "SELECT *, t.name AS tax_group_name FROM ".TB_PREF."cust_branch LEFT JOIN ".TB_PREF."areas ON area_code=area LEFT JOIN ".TB_PREF."tax_groups t ON tax_group_id=t.id WHERE debtor_no = ".db_escape($debtor_no) . " AND area_code = " .db_escape($_POST['area']);
                            $result = db_query($sql, "Could not load branch");
                            if (db_num_rows($result) != 0)
                                break;

                            display_error("Customer branch for area " . $_POST['area'] . " not found");
                            display_error('Skipping order ' . $oID);
                            break;
                        }
                    }
                }
                $branch                  = db_fetch_assoc($result);
                // print_r($branch);

                if ($_POST['invoice'] == 1) {
                    $cart                = new Cart(ST_SALESINVOICE);
                    $pos = get_sales_point_by_name($order['payment_method']);
                    if (!$pos) {
                        display_error("osC order " . $oID . " payment method " . $order['payment_method'] . " does not exist as a Point of Sale in FA.  Do Setup->Points of Sale first.");
                        if ($errors == 0) {
                            display_error('Skipping order ' . $oID);
                            continue;
                        }
                    }
                    $cart->pos=$pos;
                    $cart->payment = $_POST['payment'];
                    $cart->payment_terms = get_payment_terms($cart->payment);
                    if ($cart->payment_terms['cash_sale']) {
                        $cart->Location = $cart->pos['pos_location'];
                        $cart->location_name = $cart->pos['location_name'];
                    }
                } else {
                    $cart                = new Cart(ST_SALESORDER);
                    $cart->Location      = $branch['default_location'];
                }

                if ($customer['sales_type'] == 0) {
                    display_error("zero customer sales type");
                    display_error(print_r($customer, true));
                    display_error('Skipping order ' . $oID);
                    continue;
                }

                // Now Add Sales_Order and Sales_Order_Details
                $cart->customer_id       = $customer['debtor_no'];
                $cart->customer_currency = $customer['curr_code'];
                $cart->set_branch(
                    $branch["branch_code"],
                    $branch["tax_group_id"],
                    $branch["tax_group_name"]);

                $cart->cust_ref          = "osC Order # $oID";
                $cart->Comments          = $comments;
                $cart->document_date     = sql2date($date_purchased);
                $cart->sales_type        = $customer['sales_type'];
                $cart->ship_via          = $branch['default_ship_via'];
                $cart->deliver_to        = $branch['br_name'];
                $cart->delivery_address  = $branch['br_address'];
                $cart->phone             = $order['customers_telephone'];
                $cart->email             = $order['customers_email_address'];
                $cart->freight_cost      = $total_shipping;
                $cart->due_date          = sql2date($date_purchased);
                $cart->dimension_id      = $_POST['dimension_id'];
                $cart->dimension2_id     = $_POST['dimension2_id'];

                // calculate the FA line item discount based on order discount
                $disc_percent = 0;
                if ($total_discount != 0) {
                    // total_subtotal can be zero if the order does
                    // not contain any positive quantities and there is
                    // a discount.  This happens occasionally when a customer
                    // is overbilled; perhaps a clerk fumble fingered
                    // a credit card machine.   The customer is due a refund,
                    // but FA does not have an easy way to create a direct
                    // credit memo.  Instead, a negative quantity credit memo
                    // item is added to the invoice and is priced out at
                    // refund amount, resulting in a negative total invoice.
                    if ($total_subtotal == 0) {
                        add_to_order($cart, $_POST['credit_memo'], -1, $total_discount, 0);
                        $disc_percent = 0;
                    } else {

            // line discounts need a lot of precision (decimal) for import to work
            // but percent_format() has to be called reducing precision because
            // FA calls percent_format during order generation and if the order
            // does not add up, a Foreign Exchange Currency adjustment will be made.
            // 6 decimal places seems to be safe.

                        $disc_percent = percent_format($total_discount/$total_subtotal);
                    }
                }

                $sql                     = "SELECT * FROM orders_products WHERE orders_id = ".osc_escape($oID);
                $result                  = osc_dbQuery($sql, true);
                $rows                    = db_num_rows($result);
                $total                   = 0;
                while ($prod = mysqli_fetch_assoc($result)) {
                    $osc_id = $osc_Prefix . $prod[$osc_Id];
                    $sql    = "SELECT stock_id FROM ".TB_PREF."stock_master WHERE stock_id=".db_escape($osc_id);
                    $item = db_query($sql, "could not get item");
                    $row  = db_fetch_row($item);
                    if (!$row) {

                        display_error("osC order " . $oID . " item " . $osc_id . " not in FA.  Do an Item Import first.");
                        break;  // total check below will fail
                    }

            // Note: OSC attributes are handled in FA as two different
            // products. An OSC order of a product with attributes
            // is an order of two products in FA:
            // the parent product and the child attribute

                    $sql = "SELECT pa.products_attributes_id, opa.options_values_price
                            FROM orders_products_attributes opa
                                LEFT JOIN products_options po ON opa.products_options = po.products_options_name
                                LEFT JOIN products_options_values pov ON opa.products_options_values=pov.products_options_values_name
                                LEFT JOIN orders_products op ON op.orders_products_id=opa.orders_products_id
                                LEFT JOIN products_attributes pa ON op.products_id=pa.products_id
                                    AND po.products_options_id=pa.options_id
                                    AND pov.products_options_values_id=pa.options_values_id
                            WHERE pa.products_id=".osc_escape($prod[$osc_Id])." 
                              AND opa.orders_products_id=".osc_escape($prod['orders_products_id']);

                    $pa_result = osc_dbQuery($sql, true);
                    while ($pa = mysqli_fetch_assoc($pa_result)) {
                        $pa_osc_id = $osc_id . "-" . $pa['products_attributes_id']; 
                        $sql    = "SELECT stock_id FROM ".TB_PREF."stock_master WHERE stock_id=".db_escape($pa_osc_id);
                        $item = db_query($sql, "could not get item");
                        $row  = db_fetch_row($item);
                        if (!$row) {

                            display_error("osC order " . $oID . " item " . $pa_osc_id . " not in FA.  Do an Item Import first.");
                            break;  // total check below will fail
                        }
                        $total += round($prod['products_quantity'] * $prod['products_price'] * (1 -$disc_percent),2);
                        add_to_order($cart, $pa_osc_id, $prod['products_quantity'], $pa['options_values_price'], $disc_percent);
                    }
                    mysqli_free_result($pa_result);

                    $line_total = round($prod['products_quantity'] * $prod['products_price'] * (1 - $disc_percent),2);
                    $total += $line_total;
                    $rows--;
                    $total_osc = round($total_subtotal-$total_discount, 2);

                    // uh oh, rounding broke the invoice total
                    // change the discount of the last item to fix it
                    if ($rows == 0
                        && round($total,2) != $total_osc
                        && abs($total-$total_osc) < .05) {
                        $disc_percent = 1 - (($line_total - ($total - $total_osc))/($prod['products_quantity'] * $prod['products_price']));
                        display_notification("osC order $oID total $total not equal to $total_osc, new discount $disc_percent");
                    }
                    add_to_order($cart, $osc_id, $prod['products_quantity'], $prod['products_price'], $disc_percent);
                }
                mysqli_free_result($result);

                if ($total_total != round($cart->get_trans_total(), 2)) {
                    display_error("osC order $oID total $total_total does not match FA total " . $cart->get_trans_total() . ". (subtotal=" . $total_subtotal . " discount=".$total_discount." disc_percent=".$disc_percent." " .print_r($branch, true).")");
                    if ($errors == 0) {
                        display_error('Skipping order ' . $oID);
                        continue;
                    }
                }

                if ($_POST['invoice'] == 1) {
                    if (!$SysPrefs->allow_negative_stock() && ($low_stock = $cart->check_qoh())) {
                        display_error(_("This document cannot be processed because there is insufficient quantity for items: " . implode(' ', $low_stock) . " on " . $cart->document_date));
                        if ($errors == 0) {
                            display_error('Skipping order ' . $oID);
                            continue;
                        }
                    }

                    if (!$SysPrefs->allow_negative_invoice &&
                        $cart->get_items_total() < 0) {
                        display_error("Invoice total amount cannot be less than zero.");
                        if ($errors == 0) {
                            display_error('Skipping order ' . $oID);
                            continue;
                        }
                    }
                }

                if ($_POST['trial_run'] == 0) {
                    if ($_POST['invoice'] == 1)
                        $order_no = $cart->write(1);
                    else
                        $order_no = $cart->write(0);
                    display_notification("Added Order Number $order_no for " . $customers_name);

                    $comments="Imported into FA";
                    $sql = "INSERT INTO orders_status_history (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES (" . osc_escape($oID) . "," .  $order['orders_status']. "," . osc_escape(date('Y-m-d H:i:s')) . ", 0, " . osc_escape($comments) . ")";

                    $result = osc_dbQuery($sql);

                    if ($date_purchased > $lastdate) {
                        $sql = "UPDATE  ".TB_PREF."oscommerce SET value = ".db_escape($date_purchased)." WHERE name = 'lastdate'";
                        db_query($sql, "Update 'lastdate'");
                    }

                    $last_date = $end_date;
                }
            }
            mysqli_free_result($oid_result);

            $order_count = osc_new_orders($last_date, $statusId);
            $action = 'oimport';
        }

        if ($action == 'p_check') { // Price Check

            $sql = "SELECT p." . $osc_Id . ", p.products_id, products_price, products_name FROM products p LEFT JOIN products_description pd ON p.products_id = pd.products_id WHERE products_status = 1";
            // echo $sql;
            $p_result         = osc_dbQuery($sql, true);
            $currency         = $_POST['currency'];
            $sales_type       = $_POST['sales_type'];
            $num_price_errors = 0;
            while ($pp = mysqli_fetch_assoc($p_result)) {
                $price   = $pp['products_price'];
                $osc_id = $osc_Prefix . $pp[$osc_Id];
                $myprice = false;
                $myprice = get_kit_price($osc_id, $currency, $sales_type);
                if ($myprice === false) display_notification("$osc_id price not found in FA");
                else if ($price != $myprice) {
                    $products_name=$pp['products_name'];
                    display_notification("$osc_id $products_name : FA price $myprice does not match osC $price");
                    $num_price_errors++;
                }

                // Check for product attributes
                // FA item number like oscXXXX-AAAA

                $sql = "SELECT products_attributes_id, options_values_price, products_options_values_name FROM products_attributes pa LEFT JOIN products_options_values po ON pa.options_values_id=products_options_values_id WHERE products_id=" . $pp['products_id'];

                $pa_result = osc_dbQuery($sql, true);
                while ($pa = mysqli_fetch_assoc($pa_result)) {
                    $pa_price = $price + $pa['options_values_price'];
                    $pa_osc_id = $osc_id . "-" . $pa['products_attributes_id']; 
                    $myprice = false;
                    $myprice = get_kit_price($pa_osc_id, $currency, $sales_type);
                    if ($myprice === false) display_notification("$osc_id price not found in FA");
                    else if ($pa_price != $myprice) {
                        $products_name=$pp['products_name'];
                        $pa_products_name=$pa['products_options_values_name'];
                        display_notification("$pa_osc_id $products_name $pa_products_name : FA price $myprice does not match osC $pa_price");
                        $num_price_errors++;
                    }
                }
                mysqli_free_result($pa_result);
            }
            mysqli_free_result($p_result);
            $action = 'pcheck';
        }

        if ($action == 'p_update') { // Price Update
            $sql              = "SELECT " . $osc_Id . ", products_price FROM products WHERE products_status = 1";
            $p_result         = osc_dbQuery($sql, true);
            $currency         = $_POST['currency'];
            $sales_type       = $_POST['sales_type'];
            $num_price_errors = 0;
            while ($pp = mysqli_fetch_assoc($p_result)) {
                $price   = $pp['products_price'];
                $osc_id = $osc_Prefix . $pp[$osc_Id];
                $myprice = false;
                $myprice = get_kit_price($osc_id, $currency, $sales_type);
                if ($myprice === false) display_notification("$osc_id price not found in FA");
                else if ($price != $myprice) {
                    display_notification("Updating $osc_id from $price to $myprice");
                    $sql = "UPDATE products SET products_price = ".osc_escape($myprice)." WHERE $osc_Id = ".osc_escape($osc_id);
                    $result = osc_dbQuery($sql);
                    $num_price_errors++;
                }

                // Check for product attributes
                // FA item number like oscXXXX-AAAA

                $sql = "SELECT products_attributes_id, options_values_price, products_options_values_name FROM products_attributes pa LEFT JOIN products_options_values po ON pa.options_values_id=products_options_values_id WHERE products_id=" . $pp['products_id'];

                $pa_result = osc_dbQuery($sql, true);
                while ($pa = mysqli_fetch_assoc($pa_result)) {
                    $pa_price = $price + $pa['options_values_price'];
                    $pa_att_id = $pa['products_attributes_id']; 
                    $pa_osc_id = $osc_id . "-" . $pa_att_id;
                    $pa_myprice = false;
                    $pa_myprice = get_kit_price($pa_osc_id, $currency, $sales_type);
                    if ($pa_myprice === false) display_notification("$pa_osc_id price not found in FA");
                    else if ($pa_price != $pa_myprice) {
                        display_notification("Updating $pa_osc_id from $pa_price to $pa_myprice");
                        $sql = "UPDATE products_attributes SET options_values_price = ".osc_escape($pa_myprice - $myprice)." WHERE products_attributes_id = ".osc_escape($pa_att_id);
                        $result = osc_dbQuery($sql);
                        $num_price_errors++;
                    }
                }
                mysqli_free_result($pa_result);
            }
            mysqli_free_result($p_result);
            $action = 'pupdate';
        }
        if ($action == 'i_import') { // Item Import
            $sql = "SELECT p." . $osc_Id . ", p.products_id, pd.products_name, cd.categories_name, p.products_price, p.products_quantity, tc.tax_class_title, p.products_status
                    FROM products p
                        LEFT JOIN products_description pd ON p.products_id=pd.products_id
                        LEFT JOIN products_to_categories pc ON p.products_id=pc.products_id
                        LEFT JOIN categories_description cd ON pc.categories_id=cd.categories_id
                        LEFT JOIN tax_class tc ON p.products_tax_class_id=tc.tax_class_id";

            $p_result = osc_dbQuery($sql, true);
            while ($pp = mysqli_fetch_assoc($p_result)) {
                // WARNING: language character encoding import issue
                // default FA iso-8859-1 requires utf8_decode
                // $products_name = utf8_decode($pp['products_name']);
                $products_name = $pp['products_name'];
                $products_price = $pp['products_price'];
                $products_quantity = $pp['products_quantity'];
                $products_status = $pp['products_status'];
                $mb_flag = 'B';
                if ($products_quantity == "")
                    $mb_flag = 'D';
                $osc_id = $osc_Prefix . $pp[$osc_Id];
                $tax_class_title = $pp['tax_class_title'];
                if ($tax_class_title == "")
                    $tax_class_title = "--none--";

                $row = get_item_tax_type_by_name($tax_class_title);
                if (!$row) {
                    add_item_tax_type($tax_class_title, 0, array());
                    $tax_type_id = db_insert_id();
                    display_notification("Add Item Tax Type " . $tax_class_title);
                } else
                    $tax_type_id = $row['id'];

                $row = get_item_category_by_name($pp['categories_name']);
                if (!$row) {
                    add_item_category($pp['categories_name'], 
                    $tax_type_id,
                    $_POST['sales_account'],
                    $_POST['cogs_account'],
                    $_POST['inventory_account'],
                    $_POST['adjustment_account'],
                    $_POST['wip_account'],
                    $_POST['units'],
                    "B",
                    "",
                    0,
                    0,
                    0
                    );
                    $cat = db_insert_id();
                    display_notification("Add Category " . $pp['categories_name']);
                } else
                    $cat = $row['category_id'];

                addItemToFA($osc_id, $products_name, $cat, $tax_type_id, $mb_flag, $products_price, $products_status);

                // Check for product attributes
                // FA item number like oscXXXX-AAAA

                $sql = "SELECT products_attributes_id, options_values_price, products_options_values_name FROM products_attributes pa LEFT JOIN products_options_values po ON pa.options_values_id=products_options_values_id WHERE products_id=" . $pp['products_id'];

                $pa_result = osc_dbQuery($sql, true);
                while ($pa = mysqli_fetch_assoc($pa_result)) {
                    addItemToFA($osc_id . "-" . $pa['products_attributes_id'], $products_name . "-" . $pa['products_options_values_name'], $cat, $tax_type_id, $mb_flag, $pa['options_values_price'], $products_status);
                }
                mysqli_free_result($pa_result);
            }
            mysqli_free_result($p_result);
            $action = 'iimport';
        }

        if ($action == 'i_check') { // Inventory Check

            $sql = "SELECT p." . $osc_Id . ", p.products_id, products_quantity, products_name FROM products p LEFT JOIN products_description pd ON p.products_id = pd.products_id WHERE LENGTH(products_quantity) != 0";
            $p_result = osc_dbQuery($sql, true);
            $num_qty_errors = 0;
            while ($pp = mysqli_fetch_assoc($p_result)) {
                $qty   = $pp['products_quantity'];
                $osc_id = $osc_Prefix . $pp[$osc_Id];
                $myqty = get_qoh_on_date($osc_id, $_POST['default_location']);
                if ($qty != $myqty) {
                    $products_name=$pp['products_name'];
                    display_notification("$osc_id $products_name : FA quantity $myqty does not match osC $qty");
                    $num_qty_errors++;
                }

                // Check for product attributes
                // FA item number like oscXXXX-AAAA

                $sql = "SELECT products_attributes_id, options_quantity, products_options_values_name FROM products_attributes pa LEFT JOIN products_options_values po ON pa.options_values_id=products_options_values_id WHERE options_quantity != '' AND products_id=" . $pp['products_id'];

                $pa_result = osc_dbQuery($sql, true);
                while ($pa = mysqli_fetch_assoc($pa_result)) {
                    $pa_qty = $pa['options_quantity'];
                    $pa_osc_id = $osc_id . "-" . $pa['products_attributes_id']; 
                    $pa_myqty = get_qoh_on_date($pa_osc_id, $_POST['default_location']);
                    if ($pa_qty != $pa_myqty) {
                        $products_name=$pp['products_name'];
                        $pa_products_name=$pa['products_options_values_name'];
                        display_notification("$pa_osc_id $products_name $pa_products_name : FA quantity $pa_myqty does not match osC $pa_qty");
                        $num_qty_errors++;
                    }
                }
                mysqli_free_result($pa_result);
            }
            mysqli_free_result($p_result);
            $action = 'icheck';
        }

        if ($action == 'i_update') { // Update Inventory
            $invCust = $_POST['invCust'];
            $sql = "INSERT INTO ".TB_PREF."oscommerce (name, value) VALUES ('invCust', ".db_escape($invCust).") ON DUPLICATE KEY UPDATE name='invCust', value=".db_escape($invCust);
            db_query($sql, "Update 'invCust'");

            if ($invCust != '') {
                $sql = "INSERT INTO orders SET
                    customers_id='$invCust',
                    customers_name='FA Inventory Adjust',
                    orders_status='3',
                    date_purchased='" . date2sql(Today()) . "',
                    last_modified='" . date2sql(Today()) . "'";
                $insert_osc_result = osc_dbQuery($sql);
                $insert_id=osc_insert_id();

                $sql = "INSERT INTO orders_total set
                    orders_id='$insert_id',
                    class='ot_total',
                    title='Total:',
                    text='$0.00'";
                $insert_osc_result = osc_dbQuery($sql);

                $comments="Imported into FA";
                $sql = "INSERT INTO orders_status_history (orders_id, orders_status_id, date_added, customer_notified, comments) VALUES (" . $insert_id . "," .  '3' . "," . osc_escape(date('Y-m-d H:i:s')) . ", 0, " . osc_escape($comments) . ")";
                osc_dbQuery($sql);
            }

            $sql = "SELECT p." . $osc_Id . ", products_quantity, products_name, products_model FROM products p LEFT JOIN products_description pd ON p.products_id=pd.products_id WHERE LENGTH(products_quantity) != 0";
            $p_result = osc_dbQuery($sql, true);
            $num_qty_errors = 0;
            while ($pp = mysqli_fetch_assoc($p_result)) {
                $qty   = $pp['products_quantity'];
                $osc_id = $osc_Prefix . $pp[$osc_Id];
                $myqty = get_qoh_on_date($osc_id, $_POST['default_location']);
                $diff = $myqty - $qty;
                if ($diff != 0) {
                    display_notification("Updating $osc_id from $qty to $myqty");
                    $sql = "UPDATE products SET products_quantity = ".osc_escape($myqty)." WHERE $osc_Id = ".osc_escape($pp[$osc_Id]);
                    $result = osc_dbQuery($sql);
                    if ($invCust != '') {
                    $sql = "INSERT INTO orders_products set
                        orders_id='$insert_id',
                        products_id=".osc_escape($pp['products_id']).",
                        products_model=".osc_escape($pp['products_model']).",
                        products_name=".osc_escape($pp['products_name']).",
                        products_quantity=".osc_escape(-$diff);
                        $result = osc_dbQuery($sql);
                        $p_insert_id=osc_insert_id();
                    }

                    $num_qty_errors++;
                }

                // Check for product attributes
                // FA item number like oscXXXX-AAAA

                $sql = "SELECT products_attributes_id, options_quantity, products_options_values_name FROM products_attributes pa LEFT JOIN products_options_values po ON pa.options_values_id=products_options_values_id WHERE options_quantity !='' AND products_id=" . $pp['products_id'];

                $pa_result = osc_dbQuery($sql, true);
                while ($pa = mysqli_fetch_assoc($pa_result)) {
                    $pa_qty = $pa['options_quantity'];
                    $pa_att_id = $pa['products_attributes_id']; 
                    $pa_osc_id = $osc_id . "-" . $pa_att_id;
                    $pa_myqty = get_qoh_on_date($pa_osc_id, $_POST['default_location']);
                    $diff = $pa_myqty - $pa_qty;
                    if ($diff != 0) {
                        display_notification("Updating $pa_osc_id from $pa_qty to $pa_myqty");
                        $sql = "UPDATE products_attributes SET options_quantity = ".osc_escape($pa_myqty)." WHERE products_attributes_id = ".osc_escape($pa_att_id);
                        $result = mysqli_query($osc, $sql);

                        if ($invCust != '' && isset($p_insert_id)) {
                        $sql = "INSERT INTO orders_products_attributes set
                            orders_id='$insert_id',
                            orders_products_id='$p_insert_id',
                            products_id=".osc_escape($pp['products_id']);
                            $result = mysqli_query($osc, $sql);
                        }
                        $num_qty_errors++;
                    }
                }
                mysqli_free_result($pa_result);
            }
            mysqli_free_result($p_result);
            $action = 'iupdate';
        }

        if ($osc && !$one_database) mysqli_close($osc);
   }


} else {
    $dbHost          = $db_Host;
    $dbUser          = $db_User;
    $dbPassword      = $db_Password;
    $dbName          = $db_Name;
    $oscId           = $osc_Id;
    $oscPrefix       = $osc_Prefix;
    $lastcid         = $last_cid;
    $lastdate        = $last_date;
    $defaultTaxGroup = $default_TaxGroup;
}

if ( in_array($action, array('summary', 'cimport', 'oimport', 'iimport', 'iupdate')) && ($osc = osc_connect()) ) {

    if ($action == 'cimport' || $action == 'summary' || $action == 'iupdate') { // Preview Customer Import page

        $sql     = "SELECT `customers_id` FROM `customers` order by `customers_id` asc LIMIT 0,1";
        $cid     = osc_dbQuery($sql);
        $min_cid = (int) $cid['customers_id'];
        if ($min_cid <= $last_cid) $min_cid = $last_cid + 1;
        $sql     = "SELECT `customers_id` FROM `customers` order by `customers_id` desc LIMIT 0,1";
        $cid     = osc_dbQuery($sql);
        $max_cid = (int) $cid['customers_id'];

        $order_count = osc_new_orders($last_date, $statusId);
    }

    if ($action == 'oimport' || $action == 'summary' || $action == 'iupdate') { // Preview Order Import page

        $sql      = "SELECT `date_purchased` FROM `orders` ORDER BY `date_purchased` ASC LIMIT 0,1";
        $oid      = osc_dbQuery($sql);
        $min_date = $oid['date_purchased'];
        if ($min_date <= $last_date) $min_date = date('Y-m-d', strtotime($last_date .' +1 DAY'));
        $sql      = "SELECT `date_purchased` FROM `orders` ORDER BY `date_purchased` DESC LIMIT 0,1";
        $oid      = osc_dbQuery($sql);
        $max_date = $oid['date_purchased'];
    }

    if ($action == 'iimport' || $action == 'summary') { // Preview Item Import page

        // TBD
    }
    if ($osc && !$one_database) mysqli_close($osc);
}

$js = "";
if ($SysPrefs->use_popup_windows)
    $js .= get_js_open_window(800, 500);
if (user_use_date_picker())
        $js .= get_js_date_picker();

$help_context="osCommerce Interface";
page(_($help_context), false, false, "", $js);

if ($action == 'summary') echo 'Summary';
else hyperlink_params($_SERVER['PHP_SELF'], _("Summary"), "action=summary", false);
echo '&nbsp;|&nbsp;';
if ($action == 'show') echo 'Configuration';
else hyperlink_params($_SERVER['PHP_SELF'], _("Configuration"), "action=show", false);
echo '&nbsp;|&nbsp;';
if ($action == 'cimport') echo 'Customer Import';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Customer Import"), "action=cimport", false);
echo '&nbsp;|&nbsp;';
if ($action == 'oimport') echo 'Order Import';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Order Import"), "action=oimport", false);
echo '&nbsp;|&nbsp;';
if ($action == 'pcheck') echo 'Price Check';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Price Check"), "action=pcheck", false);
echo '&nbsp;|&nbsp;';
if ($action == 'pupdate') echo 'Update Prices';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Update Prices"), "action=pupdate", false);
echo '&nbsp;|&nbsp;';
if ($action == 'iimport') echo 'Item Import';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Item Import"), "action=iimport", false);
echo '&nbsp;|&nbsp;';
if ($action == 'icheck') echo 'Inventory Check';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Inventory Check"), "action=icheck", false);
echo '&nbsp;|&nbsp;';
if ($action == 'iupdate') echo 'Update Inventory';
else hyperlink_params($_SERVER['PHP_SELF'], _("&Update Inventory"), "action=iupdate", false);
echo "<br><br>";

include($path_to_root . "/includes/ui.inc");

if ($action == 'summary') {
    start_form(true);
    start_table(TABLESTYLE);

    $th = array("Type", "# of Updates Needed");
    table_header($th);

    $k = 0;

    //alt_table_row_color($k);

    label_cell("Customer Import");
    if ($min_cid > $max_cid) {
        label_cell("None");
    } else {
        label_cell($max_cid - $min_cid + 1);
    }
    end_row();
    label_cell("Order Import");
    if ($order_count == 0)
        label_cell("None");
    else
        label_cell($order_count);

    end_row();
    end_form();

    end_page();
}

if ($action == 'show') {
    start_form(true);
    start_table(TABLESTYLE);

    $th = array("Function", "Description");
    table_header($th);

    $k = 0;

    alt_table_row_color($k);

    label_cell("Table Status");
    if ($found) $table_st = "Found";
    else $table_st = "<font color=red>Not Found</font>";
    label_cell($table_st);
    end_row();

    if ($found) {
        text_row("Mysql Host", 'dbHost', $dbHost, 20, 40);
        
        text_row("User", 'dbUser', $dbUser, 20, 40);
        
        text_row("Password", 'dbPassword', $dbPassword, 20, 40);
        
        text_row("DB Name", 'dbName', $dbName, 20, 40);
        text_row("Osc Id", 'oscId', $oscId, 20, 40);
        text_row("Osc_Item Prefix", 'oscPrefix', $oscPrefix, 20, 40);

        tax_groups_list_row(_("Default Tax Group:"), 'taxgroup', $default_TaxGroup);
    }

    end_table(1);

    if (!$found) {
        hidden('action', 'create');
        submit_center('create', 'Create Table');
    } else {
        hidden('action', 'update');
        submit_center('update', 'Update Mysql');
    }

    end_form();

    end_page();
}

if ($action == 'cimport') {

    start_form(true);

    start_table(TABLESTYLE2, "width=40%");

    table_section_title("Default GL Accounts");

    $company_record = get_company_prefs();

    if (!isset($_POST['sales_account']) || $_POST['sales_account'] == "")
        $_POST['sales_account'] = $company_record["default_sales_act"];

    if (!isset($_POST['sales_discount_account']) || $_POST['sales_discount_account'] == "")
        $_POST['sales_discount_account'] = $company_record["default_sales_discount_act"];

    if (!isset($_POST['receivables_account']) || $_POST['receivables_account'] == "")
        $_POST['receivables_account'] = $company_record["debtors_act"];

    if (!isset($_POST['payment_discount_account']) || $_POST['payment_discount_account'] == "")
        $_POST['payment_discount_account'] = $company_record["default_prompt_payment_act"];

    gl_all_accounts_list_row("Sales Account:", 'sales_account', $_POST['sales_account']);
    gl_all_accounts_list_row("Sales Discount Account:", 'sales_discount_account', $_POST['sales_discount_account']);
    gl_all_accounts_list_row("Receivables Account:", 'receivables_account', $_POST['receivables_account']);
    gl_all_accounts_list_row("Payment Discount Account:", 'payment_discount_account', $_POST['payment_discount_account']);

    $dim = get_company_pref('use_dimension');
    if ($dim < 1)
        hidden('dimension_id', 0);
    if ($dim < 2)
        hidden('dimension2_id', 0);

    global $SysPrefs;
    $credit_limit = price_format($SysPrefs->default_credit_limit());

    table_section_title("Location, Tax Type, Sales Type, Sales Person and Payment Terms");
    locations_list_row("Location:", 'default_location', null);
    tax_groups_list_row(_("Default Tax Group:"), 'tax_group_id', $default_TaxGroup);
    sales_types_list_row("Sales Type:", 'sales_type', null);
    sales_persons_list_row("Sales Person:", 'salesman', null);
    sales_areas_list_row("Sales Area:", 'area');
    currencies_list_row("Customer Currency:", 'currency', get_company_pref("curr_default"));
    payment_terms_list_row("Payment Terms:", 'payment_terms', null);
    amount_row(_("Credit Limit:"), 'credit_limit', $credit_limit);

    if ($dim >= 1)
        dimensions_list_row(_("Dimension")." 1:", 'dimension_id', NULL, true, " ", false, 1);
    if ($dim > 1)
        dimensions_list_row(_("Dimension")." 2:", 'dimension2_id', NULL, true, " ", false, 2);

    text_row("Starting osC Customer ID:", 'min_cid', $min_cid, 6, 6);
    text_row("Ending osC Customer ID:", 'max_cid', $max_cid, 6, 6);

    end_table(1);

    hidden('action', 'c_import');
    submit_center('cimport', "Import osC Customers");

    end_form();
    end_page();
}

if ($action == 'oimport') {

    start_form(true);

    start_table(TABLESTYLE2, "width=40%");

    table_section_title("Order Import Options");

    if (!isset($_POST['first_date']))
        $_POST['first_date'] = sql2date($min_date);
    if (!isset($_POST['last_date']))
        $_POST['last_date'] = sql2date($max_date);
    date_row("Starting Order Date:", 'first_date');
    date_row("Last Order Date:", 'last_date');
    text_row("Skip Osc Status Id", 'statusId', $statusId, 20, 40);
    customer_list_row(_("Destination Customer:"), 'destCust', $destCust, true);
    sales_areas_list_row("Sales Area:", 'area');
    $dim = get_company_pref('use_dimension');
    if ($dim < 1)
        hidden('dimension_id', 0);
    if ($dim < 2)
        hidden('dimension2_id', 0);

    if ($dim >= 1)
        dimensions_list_row(_("Dimension")." 1:", 'dimension_id', null, true, " ", false, 1);
    if ($dim > 1)
        dimensions_list_row(_("Dimension")." 2:", 'dimension2_id', null, true, " ", false, 2);

    yesno_list_row(_("Direct Invoice"), 'invoice', null, "", "", false);
    sale_payment_list_cells(_('Payment:'), 'payment', PM_CASH, null, false);
    sales_service_items_list_row(_('Credit Memo Item:'),'credit_memo', null, false, false, false);

    yesno_list_row(_("Errors"), 'errors', null, "Ignore", "Skip", false);
    yesno_list_row(_("Trial Run"), 'trial_run', null, "", "", false);

    if ($destCust != 0) {
            $sql    = "SELECT name FROM ".TB_PREF."debtors_master WHERE debtor_no=".$destCust;
            $result = db_query($sql, "customer could not be retrieved");
            $row    = db_fetch_assoc($result);

    }

    end_table(1);

    hidden('action', 'o_import');
    submit_center('oimport', "Import osC Orders");

    end_form();
    end_page();
}

if ($action == 'pcheck') {

    start_form(true);

    start_table(TABLESTYLE2, "width=40%");

    table_section_title("Price Check Options");

    $company_record = get_company_prefs();

    currencies_list_row("Customer Currency:", 'currency', get_company_pref("curr_default"));
    sales_types_list_row("Sales Type:", 'sales_type', null);

    end_table(1);

    hidden('action', 'p_check');
    submit_center('pcheck', "Check osC Prices");
    if ($num_price_errors == 0) display_notification("No Pricing Errors Found");

    end_form();

    hyperlink_params($_SERVER['PHP_SELF'], _("Refresh"), "action=pcheck");
    end_page();
}

if ($action == 'pupdate') {

    start_form(true);

    start_table(TABLESTYLE2, "width=40%");

    table_section_title("Update Price Options");

    $company_record = get_company_prefs();

    currencies_list_row("Customer Currency:", 'currency', get_company_pref("curr_default"));
    sales_types_list_row("Sales Type:", 'sales_type', null);

    end_table(1);

    hidden('action', 'p_update');
    submit_center('pupdate', "Update osC Prices");
    if ($num_price_errors > 0) display_notification("There were $num_price_errors prices updated");

    end_form();
    end_page();
}

if ($action == 'iimport') {

    start_form(true);

    start_table(TABLESTYLE2, "width=40%");

    table_section_title("Import Items");
    $company_record = get_company_prefs();

    locations_list_row("Location:", 'default_location', null);

    $dim = get_company_pref('use_dimension');
    if ($dim < 1)
        hidden('dimension_id', 0);
    if ($dim < 2)
        hidden('dimension2_id', 0);

    if ($dim >= 1)
        dimensions_list_row(_("Dimension")." 1:", 'dimension_id', null, true, " ", false, 1);
    if ($dim > 1)
        dimensions_list_row(_("Dimension")." 2:", 'dimension2_id', null, true, " ", false, 2);

    if (!isset($_POST['inventory_account']) || $_POST['inventory_account'] == "")
        $_POST['inventory_account'] = $company_record["default_inventory_act"];

    if (!isset($_POST['cogs_account']) || $_POST['cogs_account'] == "")
        $_POST['cogs_account'] = $company_record["default_cogs_act"];

    if (!isset($_POST['sales_account']) || $_POST['sales_account'] == "")
        $_POST['sales_account'] = $company_record["default_inv_sales_act"];

    if (!isset($_POST['adjustment_account']) || $_POST['adjustment_account'] == "")
        $_POST['adjustment_account'] = $company_record["default_adj_act"];

    if (!isset($_POST['wip_account']) || $_POST['wip_account'] == "")
        $_POST['wip_account'] = $company_record["default_wip_act"];

    if (!isset($_POST['units']) || $_POST['units'] == "")
        $_POST['units'] = null;

    gl_all_accounts_list_row("Sales Account:", 'sales_account', $_POST['sales_account']);
    gl_all_accounts_list_row("Inventory Account:", 'inventory_account', $_POST['inventory_account']);
    gl_all_accounts_list_row("C.O.G.S. Account:", 'cogs_account', $_POST['cogs_account']);
    gl_all_accounts_list_row("Inventory Adjustments Account:", 'adjustment_account', $_POST['adjustment_account']);
    gl_all_accounts_list_row("Item Assembly Costs Account:", 'wip_account', $_POST['wip_account']);
    stock_units_list_row(_('Units of Measure:'), 'units', $_POST['units'], true);

    currencies_list_row("Customer Currency:", 'currency', get_company_pref("curr_default"));
    sales_types_list_row("Sales Type:", 'sales_type', null);
    echo "<tr><td colspan=2 align=center>Note: Only description, category and tax class id are updated on items that already exist in FA.<td><tr>";

    end_table(1);

    hidden('action', 'i_import');
    submit_center('iimport', "Import osC Items");
    if ($num_qty_errors > 0) display_notification("There were $num_qty_errors items imported");

    end_form();
    end_page();
}

if ($action == 'icheck') {

    start_form(true);

    start_table(TABLESTYLE2, "width=40%");

    table_section_title("Inventory Check Options");
    locations_list_row("Location:", 'default_location', null);

    $company_record = get_company_prefs();

    end_table(1);

    hidden('action', 'i_check');
    submit_center('pcheck', "Check osC Inventory");
    if ($num_qty_errors == 0) display_notification("No Inventory Errors Found");

    end_form();

    hyperlink_params($_SERVER['PHP_SELF'], _("Refresh"), "action=icheck");
    end_page();
}

if ($action == 'iupdate') {

    if ($order_count != 0)
        display_error("Order Import required before Inventory Update ($order_count new orders)");
    else {
        start_form(true);

        start_table(TABLESTYLE2, "width=40%");

        table_section_title("Update Inventory Options");

        locations_list_row("Location:", 'default_location', null);
        text_row("Inventory Customer Id:", 'invCust', $invCust, 20, 40);
        $company_record = get_company_prefs();

        end_table(1);

        hidden('action', 'i_update');
        submit_center('pupdate', "Update osC Inventory");
        if ($num_qty_errors > 0) display_notification("There were $num_qty_errors items updated");

        end_form();
        end_page();
    }
}
