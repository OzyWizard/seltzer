<?php

/*
    Copyright 2009-2013 Edward L. Platt <ed@elplatt.com>
    Copyright 2013 David "Buzz" Bussenschutt <davidbuzz@gmail.com>

    This file is part of the Seltzer CRM Project
    westpac_payment.inc.php -  CSV uploadable payments extensions for the payment module.

    Seltzer is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    any later version.

    Seltzer is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Seltzer.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * @return This module's revision number.  Each new release should increment
 * this number.
 */
function westpac_payment_revision () {
    return 2;
}

/**
 * Install or upgrade this module.
 * @param $old_revision The last installed revision of this module, or 0 if the
 *   module has never been installed.
 */
function westpac_payment_install($old_revision = 0) {
    // Create initial database table
    if ($old_revision < 1) {
        
        // Additional payment info for westpac payments
        $sql = '
            CREATE TABLE IF NOT EXISTS `payment_westpac` (
              `pmtid` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
              `westpac_email` varchar(255) NOT NULL,
              PRIMARY KEY (`pmtid`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ';
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
        
        // Additional contact info for westpac payments
        $sql = '
            CREATE TABLE IF NOT EXISTS `contact_westpac` (
              `cid` mediumint(8) unsigned NOT NULL,
              `westpac_email` varchar(255) NOT NULL,
              PRIMARY KEY (`westpac_email`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
        ';
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
    }
    
    if ($old_revision < 2) {
        $sql = '
        ALTER TABLE `contact_westpac` DROP PRIMARY KEY, ADD PRIMARY KEY(`cid`);
        ';
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
    }
}

// DB to Object mapping ////////////////////////////////////////////////////////

/**
 * Implementation of hook_data_alter().
 * @param $type The type of the data being altered.
 * @param $data An array of structures of the given $type.
 * @param $opts An associative array of options.
 * @return An array of modified structures.
 */
function westpac_payment_data_alter ($type, $data = array(), $opts = array()) {
    switch ($type) {
        case 'payment':
            // Get westpac payments
            $pmtids = array();
            foreach ($data as $payment) { $pmtids[] = $payment['pmtid']; }
            $opts = array('pmtid' => $pmtids);
            $westpac_payment_map = crm_map(crm_get_data('westpac_payment', $opts), 'pmtid');
            // Add westpac data to each payment data
            foreach ($data as $i => $payment) {
                if (array_key_exists($payment['pmtid'], $westpac_payment_map)) {
                    $data[$i]['westpac'] = $westpac_payment_map[$payment['pmtid']];
                }
            }
    }
    return $data;
}

/**
 * Return data for one or more westpac payments.
 */
function westpac_payment_data ($opts = array()) {
    $sql = "SELECT `pmtid`, `westpac_email` FROM `payment_westpac` WHERE 1";
    if (isset($opts['pmtid'])) {
        if (is_array($opts['pmtid'])) {
            $terms = array();
            foreach ($opts['pmtid'] as $id) { $terms[] = mysql_real_escape_string($id); }
            $sql .= " AND `pmtid` IN (" . join(',', $terms) . ") ";
        } else {
            $esc_pmtid = mysql_real_escape_string($opts['pmtid']);
            $sql .= " AND `pmtid`='$esc_pmtid' ";
        }
    }
    $res = mysql_query($sql);
    if (!$res) crm_error(mysql_error());
    // Read from database and store in a structure
    $westpac_payment_data = array();
    while ($db_row = mysql_fetch_assoc($res)) {
        $westpac_payment_data[] = $db_row;
    }
    return $westpac_payment_data;
};

/**
 * Return data for one or more westpac contacts.
 *
 * @param $opts An associative array of options, possible keys are:
 *   'cid' If specified, returns all payments assigned to the contact with specified id;
 *   'filter' An array mapping filter names to filter values;
 * @return An array with each element representing a single payment.
*/
function westpac_payment_contact_data ($opts = array()) {
    $sql = "SELECT `cid`, `westpac_email` FROM `contact_westpac` WHERE 1";
    if (isset($opts['filter'])) {
        foreach ($opts['filter'] as $filter => $value) {
            if ($filter === 'westpac_email') {
                $esc_email = mysql_real_escape_string($value);
                $sql .= " AND `westpac_email`='$esc_email' ";
            }
        }
    }
    $res = mysql_query($sql);
    if (!$res) crm_error(mysql_error());
    $emails = array();
    $row = mysql_fetch_assoc($res);
    while ($row) {
        $email = array(
            'cid' => $row['cid']
            , 'westpac_email' => $row['westpac_email']
        );
        $emails[] = $email;
        $row = mysql_fetch_assoc($res);
    }
    return $emails;
}

/**
 * Save a westpac contact.  If the name is already in the database,
 * the mapping is updated.  When updating the mapping, any fields that are not
 * set are not modified.
 */
function westpac_payment_contact_save ($contact) {
    $esc_email = mysql_real_escape_string($contact['westpac_email']);
    $esc_cid = mysql_real_escape_string($contact['cid']);    
    // Check whether the westpac contact already exists in the database
    $sql = "SELECT * FROM `contact_westpac` WHERE `cid` = '$esc_cid'";
    $res = mysql_query($sql);
    if (!$res) crm_error(mysql_error());
    $row = mysql_fetch_assoc($res);
    if ($row) {
        // Name is already in database, update if the cid is set
        if (isset($contact['cid'])) {
            $sql = "
                UPDATE `contact_westpac`
                SET `westpac_email`='$esc_email'
                WHERE `cid`='$esc_cid'
            ";
            $res = mysql_query($sql);
            if (!$res) crm_error(mysql_error());
        }
    } else {
        // Name is not in database, insert new
        $sql = "
            INSERT INTO `contact_westpac`
            (`cid`, `westpac_email`) VALUES ('$esc_cid', '$esc_email')";
        $res = mysql_query($sql);
        if (!$res) crm_error(mysql_error());
    }
}

/**
 * Delete a westpac contact.
 * @param $westpac_payment_contact The westpac_payment_contact data structure to delete, must have a 'cid' element.
 */
function westpac_payment_contact_delete ($westpac_payment_contact) {
    $esc_cid = mysql_real_escape_string($westpac_payment_contact['cid']);
    $sql = "DELETE FROM `contact_westpac` WHERE `cid`='$esc_cid'";
    $res = mysql_query($sql);
    if (!$res) die(mysql_error());
    if (mysql_affected_rows() > 0) {
        message_register('Contact info deleted.');
    }
    return crm_url('westpac-admin');
}

/**
 * Update westpac_payment data when a payment is updated.
 * @param $contact The contact data array.
 * @param $op The operation being performed.
 */
function westpac_payment_payment_api ($payment, $op) {
    if ($payment['method'] !== 'westpac') {
        return $payment;
    }
    $email = $payment['westpac_email'];
    $pmtid = $payment['pmtid'];
    $credit_cid = $payment['credit_cid'];
    $esc_email = mysql_real_escape_string($email);
    $esc_pmtid = mysql_real_escape_string($pmtid);
    // Create link between the westpac payment name and contact id
    $westpac_contact = array();
    if (isset($payment['westpac_email'])) {
        $westpac_contact['westpac_email'] = $email;
    }
    if (isset($payment['credit_cid'])) {
        $westpac_contact['cid'] = $credit_cid;
    }
    switch ($op) {
        case 'insert':
            $sql = "
                INSERT INTO `payment_westpac`
                (`pmtid`, `westpac_email`)
                VALUES
                ('$esc_pmtid', '$esc_email')
            ";
            $res = mysql_query($sql);
            if (!$res) crm_error(mysql_error());
            westpac_payment_contact_save($westpac_contact);
            break;
        case 'update':
            $sql = "
                UPDATE `payment_westpac`
                SET `westpac_email` = '$esc_email'
                WHERE `pmtid` = '$esc_pmtid'
            ";
            $res = mysql_query($sql);
            if (!$res) die(mysql_error());
            westpac_payment_contact_save($westpac_contact);
            break;
        case 'delete':
            $sql = "
                DELETE FROM `payment_westpac`
                WHERE `pmtid`='$esc_pmtid'";
                $res = mysql_query($sql);
                if (!$res) crm_error(mysql_error());
            break;
    }
    return $payment;
}

/**
 * Generate payments contacts table
 *
 * @param $opts an array of options passed to the westpac_payment_contact_data function
 * @return a table (array) listing the contacts represented by all payments
 *   and their associated westpac email
 */ 
function westpac_payment_contact_table($opts){
    $data = crm_get_data('westpac_payment_contact', $opts);
    // Initialize table
    $table = array(
        "id" => '',
        "class" => '',
        "rows" => array(),
        "columns" => array()
    );
    // Check for permissions
    if (!user_access('payment_view')) {
        error_register('User does not have permission to view payments');
        return;
    }
    // Add columns
    $table['columns'][] = array("title"=>'Full Name');
    $table['columns'][] = array("title"=>'westpac Email');
    // Add ops column
    if (!$export && (user_access('payment_edit') || user_access('payment_delete'))) {
        $table['columns'][] = array('title'=>'Ops','class'=>'');
    }
    // Add rows
    foreach ($data as $union) {
        $row = array();
        //first column is the full name associated with the union['cid']
        $memberopts = array(
            'cid' => $union['cid'],
        );
        $contact = crm_get_one('contact', array('cid'=>$union['cid']));
        $contactName = '';
        if (!empty($contact)) {
            $contactName = theme('contact_name', $contact, true);
        }
        $row[] = $contactName; 
        // Second column is union['westpac_email']
        $row[] = $union['westpac_email'];
        if (!$export && (user_access('payment_edit') || user_access('payment_delete'))) {
            // Construct ops array
            $ops = array();
            // Add edit op
            if (user_access('payment_edit')) {
                $ops[] = '<a href=' . crm_url('westpac_payment_contact&cid=' . $contact['cid'] . '#tab-edit') . '>edit</a>';
            }
            // Add delete op
            if (user_access('payment_delete')) {
                $ops[] = '<a href=' . crm_url('delete&type=westpac_payment_contact&cid=' . $contact['cid']) . '>delete</a>';
            }
            // Add ops row
            $row[] = join(' ', $ops);
        }
        // Save row array into the $table structure
        $table['rows'][] = $row;
    }
    return $table; 
}

/**
 * Page hook.  Adds module content to a page before it is rendered.
 *
 * @param &$page_data Reference to data about the page being rendered.
 * @param $page_name The name of the page being rendered.
 * @param $options The array of options passed to theme('page').
*/
function westpac_payment_page (&$page_data, $page_name, $options) {
    switch ($page_name) {
        case 'payments':
            if (user_access('payment_edit')) {
                $content = theme('westpac_payment_admin');
                $content .= theme('form', crm_get_form('westpac_payment_import'));
                page_add_content_top($page_data, $content, 'Westpac');
            }
            break;
        case 'westpac-admin':
            page_set_title($page_data, 'Administer westpac Contacts');
            page_add_content_top($page_data, theme('table', 'westpac_payment_contact', array('show_export'=>true)), 'View');
            page_add_content_top($page_data, theme('form', crm_get_form('westpac_payment_contact_add')), 'Add');
            break;
        case 'westpac_payment_contact':
            // Capture westpac contact id
            $cid = $options['cid'];
            if (empty($cid)) {
                return;
            }
            
            // Set page title
            page_set_title($page_data, 'Administer westpac Contact');
            
            // Add edit tab
            if (user_access('payment_edit') || $_GET['cid'] == user_id()) {
                page_add_content_top($page_data, theme('form', crm_get_form('westpac_payment_contact_edit', $cid)), 'Edit');
            }
            
            break;
    }
}

/**
 * @return a westpac payments import form structure.
 */
function westpac_payment_import_form () {
    return array(
        'type' => 'form'
        , 'method' => 'post'
        , 'enctype' => 'multipart/form-data'
        , 'command' => 'westpac_payment_import'
        , 'fields' => array(
            array(
                'type' => 'fieldset'
                , 'label' => 'Import CSV'
                , 'fields' => array(
                    array(
                        'type' => 'message'
                        , 'value' => 'Use this form to upload westpac payments data in comma-separated (CSV) format.'
                    )
                    , array(
                        'type' => 'file'
                        , 'label' => 'CSV File'
                        , 'name' => 'payment-file'
                    )
                    , array(
                        'type' => 'submit'
                        , 'value' => 'Import'
                    )
                )
            )
        )
    );
}

/**
 * Return the form structure for the add westpac contact form.
 *
 * @param The cid of the contact to add a westpac contact for.
 * @return The form structure.
*/
function westpac_payment_contact_add_form () {
    
    // Ensure user is allowed to edit westpac contacts
    if (!user_access('payment_edit')) {
        return crm_url('westpac-admin');
    }
    
    // Create form structure
    $form = array(
        'type' => 'form',
        'method' => 'post',
        'command' => 'westpac_payment_contact_add',
        'fields' => array(
            array(
                'type' => 'fieldset',
                'label' => 'Add westpac Contact',
                'fields' => array(
                    array(
                        'type' => 'text',
                        'label' => "Member's Name",
                        'name' => 'cid',
                        'autocomplete' => 'contact_name'
                    ),
                    array(
                        'type' => 'text',
                        'label' => 'westpac Email Address',
                        'name' => 'westpac_email'
                    ),
                    array(
                        'type' => 'submit',
                        'value' => 'Add'
                    )
                )
            )
        )
    );
    
    return $form;
}

/**
 * Return the form structure for the edit westpac contact form.
 *
 * @param The cid of the contact to edit a westpac contact for.
 * @return The form structure.
*/
function westpac_payment_contact_edit_form ($cid) {
    
    // Ensure user is allowed to edit westpac contacts
    if (!user_access('payment_edit')) {
        return crm_url('westpac-admin');
    }
    
     // Get westpac contact data
    $data = crm_get_data('westpac_payment_contact', array('cid'=>$cid));
    $westpac_payment_contact = $data[0];
    
    $contactName = theme('contact_name', $westpac_payment_contact['cid'], true);
    // Create form structure
    $form = array(
        'type' => 'form',
        'method' => 'post',
        'command' => 'westpac_payment_contact_edit',
        'hidden' => array(
            'cid' => $westpac_payment_contact['cid']
        ),
        'fields' => array(
            array(
                'type' => 'fieldset',
                'label' => 'Edit westpac Contact',
                'fields' => array(
                    
                    array(
                        'type' => 'readonly',
                        'label' => "Member's Name",
                        'name' => 'name',
                        'value' => $contactName
                    ),array(
                        'type' => 'text',
                        'label' => 'westpac Email Address',
                        'name' => 'westpac_email',
                        'value' => $westpac_payment_contact['westpac_email']
                    ),
                    array(
                        'type' => 'submit',
                        'value' => 'Update'
                    )
                )
            )
        )
    );
    
    return $form;
}

/**
 * Return the delete westpac contact form structure.
 *
 * @param $cid The cid of the westpac contact to delete.
 * @return The form structure.
*/
function westpac_payment_contact_delete_form ($cid) {
    
    // Ensure user is allowed to delete westpac contacts
    if (!user_access('payment_edit')) {
        return crm_url('westpac-admin');
    }
    
    // Get westpac contact data
    $data = crm_get_data('westpac_payment_contact', array('cid'=>$cid));
    $westpac_payment_contact = $data[0];
    
    // Construct westpac contact name
    $westpac_payment_contact_name = "westpac contact:$westpac_payment_contact[cid] email:$westpac_payment_contact[westpac_email]";
    
    // Create form structure
    $form = array(
        'type' => 'form',
        'method' => 'post',
        'command' => 'westpac_payment_contact_delete',
        'hidden' => array(
            'cid' => $westpac_payment_contact['cid']
        ),
        'fields' => array(
            array(
                'type' => 'fieldset',
                'label' => 'Delete westpac Contact',
                'fields' => array(
                    array(
                        'type' => 'message',
                        'value' => '<p>Are you sure you want to delete the westpac contact "' . $westpac_payment_contact_name . '"? This cannot be undone.',
                    ),
                    array(
                        'type' => 'submit',
                        'value' => 'Delete'
                    )
                )
            )
        )
    );
    
    return $form;
}

/**
 * Implementation of hook_form_alter().
 * @param &$form The form being altered.
 * @param &$form_data Metadata about the form.
 * @param $form_id The name of the form.
 */
function westpac_payment_form_alter(&$form, $form_id) {
    if ($form_id === 'payment_edit') {
        // Modify westpac payments only
        $payment = $form['data']['payment'];
        if ($payment['method'] !== 'westpac') {
            return $form;
        }
        $westpac_payment = $payment['westpac'];
        if (empty($westpac_payment)) {
            error_register("Payment type 'westpac' but no associated data for payment:$payment[pmtid].");
            return $form;
        }
        // Loop through all fields in the form
        for ($i = 0; $i < count($form['fields']); $i++) {
            if ($form['fields'][$i]['label'] === 'Edit Payment') {
                // Add westpac email
                $email_field = array(
                    'type' => 'readonly'
                    , 'label' => 'westpac Email'
                    , 'name' => 'westpac_email'
                    , 'value' => $westpac_payment['westpac_email']
                );
                array_unshift($form['fields'][$i]['fields'], $email_field);
                // Loop through fields in Edit Payment fieldset
                $fieldset = $form['fields'][$i];
                for ($j = 0; $j < count($fieldset['fields']); $j++) {
                    // Since the payment is generated by a module,
                    // users shouldn't be able to change the method
                    if ($fieldset['fields'][$j]['name'] === 'method') {
                        $form['fields'][$i]['fields'][$j]['options'] = array('westpac' => 'westpac');
                        $form['fields'][$i]['fields'][$j]['value'] = westpac;
                    }
                }
            }
        }
    }
    return $form;
}

/**
 * Handle westpac payment import request.
 *
 * @return The url to display on completion.
 
  westpac CSV data has these headers: 
  Bank Account, Date, Narrative, Debit Amount, Credit Amount, Categories, Serial
  
 */
function command_westpac_payment_import () {
    if (!user_access('payment_edit')) {
        error_register('User does not have permission: payment_edit');
        return crm_url('payments');
    }
    if (!array_key_exists('payment-file', $_FILES)) {
        error_register('No payment file uploaded');
        return crm_url('payments&tab=import');
    }
    $tmpfilename = $_FILES['payment-file']['tmp_name'];
    $origfilename = $_FILES['payment-file']['name'];
    $csv = file_get_contents($tmpfilename);
    $data = csv_parse($csv);
    $count = 0;
    $foundname = ''; 
    
            
        // magic to figure out which member made the payment
          $sql = "SELECT cid, username FROM user WHERE 1";
          $res = mysql_query($sql);
          if (!$res) crm_error(mysql_error());
          $usernames = array();
          $row = mysql_fetch_assoc($res);
          while ($row) {
              if ( $row['username'] == "" ) continue; 
              $usernames[$row['cid']] = strtolower($row['username']);
              $row = mysql_fetch_assoc($res);
          }
          
        // magic to figure out which member made the payment
          $sql = "SELECT cid, firstName, lastName FROM contact WHERE 1";
          $res = mysql_query($sql);
          if (!$res) crm_error(mysql_error());
          $fullnames1 = array();
          $fullnames2 = array();
          $lastnames = array();
          $row = mysql_fetch_assoc($res);
          while ($row) {
              if ( $row['firstName'] == "" ) continue; 
              if ( $row['lastName'] == "" ) continue; 
              $fullnames1[$row['cid']] = strtolower($row['firstName']." ".$row['lastName']);
              $fullnames2[$row['cid']] = strtolower($row['lastName']." ".$row['firstName']);
              $lastnames[$row['cid']] = strtolower($row['lastName']);
              $row = mysql_fetch_assoc($res);
          }
           
          
          
    foreach ($data as $row) {
        
        // skip records that aren't deposits, as members can't do XFER or DEBIT
        if ( $row['Categories'] != 'DEP' ) continue; 
        
                
        // turn it into a unique reference for later possible cross-checcking.... 
        $md5 = md5($row['Bank Account'].", ".$row['Date'].", ".$row['Narrative'].", ".$row['Debit Amount'].", ".$row['Credit Amount'].", ".$row['Categories'].", ".$row['Serial']);

        
        // TODO - Skip transactions that have already been imported, paypal did it like this: 
        $payment_opts = array(
            'filter' => array('confirmation' => $md5)
        );
        $data = payment_data($payment_opts);
        if (count($data) > 0) {
            continue;
        }
        
        // Parse value
        $value = payment_parse_currency($row['Credit Amount']);
        
        // strip common non-name crud from Narative so usernames like 'Si' and 'IT' and "Lin" don't accidentially match
        $Narrative = preg_replace('/DEPOSIT /i','',$row['Narrative']); 
        $Narrative = preg_replace('/INTERNET/i','',$Narrative); 
        $Narrative = preg_replace('/ONLINE/i','',$Narrative); 
        $Narrative = preg_replace('/BANKING/i','',$Narrative); 
        $Narrative = preg_replace('/BENDIGO/i','',$Narrative); 
        $Narrative = preg_replace('/BANK OF QLD/i','',$Narrative); 
        $Narrative = preg_replace('/BANK/i','',$Narrative); 
        $Narrative = preg_replace('/PAYMENT/i','',$Narrative); 
        $Narrative = preg_replace('/MEMBERSHIP/i','',$Narrative); 
        $Narrative = preg_replace('/HSBNE/i','',$Narrative); 
        
        
        // magic to figure out which member made the payment....   ( match for a members nickname, or fullname, or if we must, by surname , any of these will do, if we get more than 1 possible match at any level, freak out and fail. 
        $method = "westpac";
        
        // look for nickname
         $found = 0; 
         $matches = ''; 
         foreach ( $usernames as $cid => $n ) { 
             
            if ( preg_match("/$n/i",$Narrative) )  { $foundname = $cid; $found++; $matches .= "&".$n; } 
          } 
        if ( $found > 1 ) crm_error("Too many usernames matched this payment - failed, sorry. ( $matches)  ( $Narrative )  ".print_r($row,true)); 
        if ( $found == 1 ) { $method = "nickname_match"; } 
        
        // TODO look for fullname 
        //select firstName, lastName from contact 
        if ( $found == 0  ) { 
            foreach ( $fullnames1 as $cid => $n ) { 
               if ( preg_match("/$n/i",$Narrative) )  {  $foundname = $cid; $found++;  } 
             } 
            if ( $found > 1 ) crm_error("Too many \$fullnames1 matched this payment - failed, sorry. ".print_r($row,true)); 
        }
        if ( $found == 1 ) { $method = "first_last_match"; } 
        
        if ( $found == 0  ) { 
            foreach ( $fullnames2 as $cid => $n ) { 
               if ( preg_match("/$n/i",$Narrative) )  {  $foundname = $cid;  $found++;  } 
             } 
            if ( $found > 1 ) crm_error("Too many \$fullnames2 matched this payment - failed, sorry. ".print_r($row,true)); 
        }
        if ( $found == 1 ) { $method = "last_first_match"; } 
                
        // TODO look for surname 
        //select lastName from contact 
        if ( $found == 0  ) { 
            foreach ( $lastnames as $cid => $n ) { 
               if ( preg_match("/$n/i",$Narrative) )  { $foundname = $cid;  $found++;  } 
             } 
            if ( $found > 1 ) crm_error("Too many \$lastnames matched this payment - failed, sorry. ".print_r($row,true)); 
        }
        if ( $found == 1 ) { $method = "lastname_only_match"; } 

        
        if ( $found == 0  ) {
            crm_error("No member matched this payment - failed, sorry.  ( $Narrative ) ".print_r($row,true) );
            $foundname = 0; 
        }
        if ( $foundname == "" ) {
            crm_error("No membername matched this payment - failed, sorry.  ( $Narrative ) ".print_r($row,true) );
            $foundname = 0; 
        }
        
        $date_data = split("/",$row['Date']);   // dd/mm/yy format to start with 
        $day=$date_data[0]; $month=$date_data[1]; $year=$date_data[2]; 
        
      //  print_r("$day  - $month - $year \n");
            
        // Create payment object
        $payment = array(
            'date' => date('Y-m-d', mktime(0, 0, 0, $month, $day, $year)) //yyyy-mm-dd format to end with
            , 'code' => $value['code']
            , 'value' => $value['value']
            , 'credit_cid' =>  $foundname   // the user ID of the member to credit..! 
            , 'description' => $Narrative . ' westpac Payment'
            , 'method' => $method
            , 'confirmation' =>  $md5  // $row['Transaction ID']
            , 'notes' => $origfilename
            , 'westpac_email' => 'not implemented'
        );
        // Check if the westpac email is linked to a contact
        //$opts = array('filter'=>array('westpac_email'=>$row['From Email Address']));
        //$contact_data = westpac_payment_contact_data($opts);
        //if (count($contact_data) > 0) {
        //    $payment['credit_cid'] = $contact_data[0]['cid'];
        //}
        // Save the payment
        //print_r($payment);
        $payment = payment_save($payment);
        $count++;
    }
    message_register("Successfully imported $count payment(s)");
    return crm_url('payments');
}

/**
 * Delete a westpac contact.
 * @param $westpac_payment_contact The westpac_payment_contact data structure to delete, must have a 'cid' element.
 */
function command_westpac_payment_contact_delete () {
    westpac_payment_contact_delete($_POST);
    return crm_url('westpac-admin');
}

/**
 * Add a westpac contact.
 * @return The url to display on completion.
 */
function command_westpac_payment_contact_add (){
    westpac_payment_contact_save($_POST);
    return crm_url('westpac-admin');
}

/**
 * Edit a westpac contact.
 * @return The url to display on completion.
 */
function command_westpac_payment_contact_edit (){
    westpac_payment_contact_save($_POST);
    return crm_url('westpac-admin');
}

/**
 * Return themed html for westpac admin links.
 */
function theme_westpac_payment_admin () {
    return '<p><a href=' . crm_url('westpac-admin') . '>Administer</a></p>';
}
