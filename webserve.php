<?php
//
// For using the WordPress API from an external program such as this, see:
// http://www.webopius.com/content/139/using-the-wordpress-api-from-pages-outside-of-wordpress
// ... including the reader comments.
//
define('WP_USE_THEMES', false);
require('../../../wp-load.php');

// Specify the forms plug-in used.  Currently only NINJA is supported.
define('FORMS_METHOD', 'NINJA');

// For use of the $wpdb object to access the WordPress database, see:
// http://codex.wordpress.org/Class_Reference/wpdb

$out = array('errmsg' => '');
$action = $_REQUEST['action'];

// While the password is sent to us as plain text, this transport interface
// should always be encrypted via SSL (HTTPS). See also:
// http://codex.wordpress.org/Function_Reference/wp_authenticate
// http://codex.wordpress.org/Class_Reference/WP_User
$user = wp_authenticate($_REQUEST['login'], $_REQUEST['password']);

if (is_wp_error($user)) {
  $out['errmsg'] = "Portal authentication failed.";
}
else if (!$user->has_cap('create_users')) {
  // This capability is arbitrary. Might want a new one for portal access.
  $out['errmsg'] = "This login does not have permission to administer the portal.";
}
else {
  if ('list'        == $action) action_list       ($_REQUEST['date_from'], $_REQUEST['date_to']); else
  if ('getpost'     == $action) action_getpost    ($_REQUEST['postid']                         ); else
  if ('getupload'   == $action) action_getupload  ($_REQUEST['uploadid']                       ); else
  if ('delpost'     == $action) action_delpost    ($_REQUEST['postid']                         ); else
  if ('checkptform' == $action) action_checkptform($_REQUEST['patient'], $_REQUEST['form']     ); else
  if ('adduser' == $action) action_adduser($_REQUEST['newlogin'], $_REQUEST['newpass'], $_REQUEST['newemail']); else
  // More TBD.
  $out['errmsg'] = 'Action not recognized!';
}

// The HTTP response content is always a JSON-encoded array.
echo json_encode($out);

function get_mime_type($filepath) {
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimetype = finfo_file($finfo, $filepath);
    finfo_close($finfo);
  }
  else {
    $mimetype = mime_content_type($filepath);
  }
  if (empty($mimetype)) $mimetype = 'application/octet-stream';
  return $mimetype;
}

// Logic to process the "list" action.
// For Ninja, a row for every form submission.
//
function action_list($date_from='', $date_to='') {
  global $wpdb, $out;
  $out['list'] = array();

  if (FORMS_METHOD == 'NINJA') {
    $query =
      "SELECT fs.id, fs.date_updated, u.user_login, f.data AS formdata " .
      "FROM {$wpdb->prefix}ninja_forms_subs AS fs " .
      "JOIN {$wpdb->prefix}ninja_forms AS f ON f.id = fs.form_id " .
      "LEFT JOIN $wpdb->users AS u ON u.ID = fs.user_id " .
      "WHERE 1 = %d";
    $qparms = array(1);
    if ($date_from) {
      $query .= " AND fs.date_updated >= %s";
      $qparms[] = "$date_from 00:00:00";
    }
    if ($date_to) {
      $query .= " AND fs.date_updated <= %s";
      $qparms[] = "$date_to 23:59:59";
    }
    $query .= " ORDER BY fs.date_updated";
    $query = $wpdb->prepare($query, $qparms);
    if (empty($query)) {
      $out['errmsg'] = "Internal error: wpdb prepare() failed.";
    }
    else {
      $rows = $wpdb->get_results($query, ARRAY_A);
      foreach ($rows as $row) {
        $formtype = '';
        $formdata = unserialize($row['formdata']);
        if (isset($formdata['form_title'])) $formtype = $formdata['form_title'];
        $out['list'][] = array(
          'postid'   => $row['id'],
          'user'     => (isset($row['user_login']) ? $row['user_login'] : ''),
          'datetime' => $row['date_updated'],
          'type'     => $formtype,
        );
      }
    }
  }

}

// Logic to process the "getpost" action.
// The $postid argument identifies the form instance.
// For Ninja the submitted field values and names must be extracted from
// serialized globs, and each field name comes from its description text.
//
function action_getpost($postid) {
  global $wpdb, $out;
  $out['post'] = array();
  $out['uploads'] = array();

  if (FORMS_METHOD == 'NINJA') {
    // wp_ninja_forms_subs has one row for each submitted form.
    // wp_ninja_forms has one row for each defined form.
    $query =
      "SELECT fs.id, fs.form_id, fs.date_updated, u.user_login, " .
      "f.data AS formdata, fs.data AS seldata " .
      "FROM {$wpdb->prefix}ninja_forms_subs AS fs " .
      "JOIN {$wpdb->prefix}ninja_forms AS f ON f.id = fs.form_id " .
      "LEFT JOIN $wpdb->users AS u ON u.ID = fs.user_id " .
      "WHERE fs.id = %d";
    $queryp = $wpdb->prepare($query, array($postid));
    if (empty($queryp)) {
      $out['errmsg'] = "Internal error: \"$query\" \"$postid\"";
      return;
    }
    $row = $wpdb->get_row($queryp, ARRAY_A);
    if (empty($row)) {
      $out['errmsg'] = "No rows matching: \"$postid\"";
      return;
    }
    $formid = $row['form_id'];
    $formtype = '';
    $formdata = unserialize($row['formdata']);
    if (isset($formdata['form_title'])) $formtype = $formdata['form_title'];
    $out['post'] = array(
      'postid'   => $row['id'],
      'user'     => (isset($row['user_login']) ? $row['user_login'] : ''),
      'datetime' => $row['date_updated'],
      'type'     => $formtype,
    );
    $out['fields'] = array();
    $out['labels'] = array();
    $seldata = unserialize($row['seldata']);
    // For each field in the form...
    foreach ($seldata as $selval) {
      $fieldid = $selval['field_id'];
      // wp_ninja_forms_fields has one row for each defined form field.
      $query2 =
        "SELECT data FROM {$wpdb->prefix}ninja_forms_fields " .
        "WHERE form_id = %d AND id = %d";
      $query2p = $wpdb->prepare($query2, array($formid, $fieldid));
      if (empty($query2p)) {
        $out['errmsg'] = "Internal error: \"$query2\" \"$postid\" \"$fieldid\"";
        continue;
      }
      // echo "$query2p\n"; // debugging
      $fldrow = $wpdb->get_row($query2p, ARRAY_A);
      if (empty($fldrow)) continue; // should not happen
      $flddata = unserialize($fldrow['data']);
      // Report uploads, if any.
      if (isset($flddata['upload_location']) && is_array($selval['user_value'])) {
        foreach ($selval['user_value'] as $uparr) {
          if (empty($uparr['upload_id'])) continue;
          $filepath = $uparr['file_path'] . $uparr['file_name'];
          // Put the info into the uploads array.
          $out['uploads'][] = array(
            'filename' => $uparr['user_file_name'],
            'mimetype' => get_mime_type($filepath),
            'id'       => $uparr['upload_id'],
          );
        }
      }
      // Each field that matches with a field name in OpenEMR must have that name in
      // its description text. Normally this is in the form of an HTML comment at the
      // beginning of this text, e.g. "<!-- field_name -->".  The regular expression
      // below picks out the name as the first "word" of the description.
      if (!preg_match('/([a-zA-Z0-9_:]+)/', $flddata['desc_text'], $matches)) continue;
      $fldname = $matches[1];
      $out['fields'][$fldname] = $selval['user_value'];
      $out['labels'][$fldname] = $flddata['label'];
    }
  }

}

// Logic to process the "delpost" action to delete a post.
//
function action_delpost($postid) {
  global $wpdb, $out;

  if (FORMS_METHOD == 'NINJA') {
    // If this form instance includes any file uploads, then delete the
    // uploaded files as well as the rows in wp_ninja_forms_uploads.
    action_getpost($postid);
    if ($out['errmsg']) return;
    foreach ($out['uploads'] as $upload) {
      $query = "SELECT fu.data " .
        "FROM {$wpdb->prefix}ninja_forms_uploads AS fu WHERE fu.id = %d";
      $drow = $wpdb->get_row($wpdb->prepare($query,
      array('id' => $upload['id'])), ARRAY_A);
      $data = unserialize($drow['data']);
      $filepath = $data['file_path'] . $data['file_name'];
      @unlink($filepath);
      $wpdb->delete("{$wpdb->prefix}ninja_forms_uploads",
        array('id' => $upload['id']), array('%d'));
    }
    $out = array('errmsg' => '');
    // Finally, delete the form instance.
    $tmp = $wpdb->delete("{$wpdb->prefix}ninja_forms_subs", array('id' => $postid), array('%d'));
    if (empty($tmp)) {
      $out['errmsg'] = "Delete failed for id '$postid'";
    }
  }

}

// Logic to process the "adduser" action to create a user as a patient.
//
function action_adduser($login, $pass, $email) {
  global $wpdb, $out;
  if (empty($login)) $login = $email;
  $userid = wp_insert_user(array(
    'user_login' => $login,
    'user_pass'  => $pass,
    'user_email' => $email,
    'role'       => 'patient',
  ));
  if (is_wp_error($userid)) {
    $out['errmsg'] = "Failed to add user '$login': " . $userid->get_error_message();
  }
  else {
    $out['userid'] = $userid;
  }
}

// Logic to process the "checkptform" action to determine if a form is pending for
// the given patient login and form name.  If it is its request ID is returned.
//
function action_checkptform($patient, $form) {
  global $wpdb, $out;
  $out['list'] = array();

  if (FORMS_METHOD == 'NINJA') {
    // MySQL pattern for matching the form name in wp_ninja_forms.data.
    $pattern = '%s:10:"form_title";s:' . strlen($form) . ':"' . $form . '";%';
    $query =
      "SELECT fs.id FROM " .
      "$wpdb->users AS u, " .
      "{$wpdb->prefix}ninja_forms_subs AS fs, " .
      "{$wpdb->prefix}ninja_forms AS f " .
      "WHERE u.user_login = %s AND " .
      "fs.user_id = u.id AND " .
      "f.id = fs.form_id AND " .
      "f.data LIKE %s " .
      "ORDER BY fs.id LIMIT 1";
    $queryp = $wpdb->prepare($query, array($patient, $pattern));
    if (empty($queryp)) {
      $out['errmsg'] = "Internal error: \"$query\" \"$patient\" \"$pattern\"";
      return;
    }
    $row = $wpdb->get_row($queryp, ARRAY_A);
    $out['postid'] = empty($row['id']) ? '0' : $row['id'];
  }

}

// Logic to process the "getupload" action.
// Returns filename, mimetype, datetime and contents for the specified upload ID.
//
function action_getupload($uploadid) {
  global $wpdb, $out;
  if (FORMS_METHOD == 'NINJA') {
    $query = "SELECT fu.data " .
      "FROM {$wpdb->prefix}ninja_forms_uploads AS fu WHERE fu.id = %d";
    $row = $wpdb->get_row($wpdb->prepare($query, array($uploadid)), ARRAY_A);
    $data = unserialize($row['data']);
    // print_r($data); // debugging
    $filepath = $data['file_path'] . $data['file_name'];
    $contents = file_get_contents($filepath);
    if ($contents === false) {
      $out['errmsg'] = "Unable to read \"$filepath\"";
      return;
    }
    $out['filename'] = $data['user_file_name'];
    $out['mimetype'] = get_mime_type($filepath);
    $out['datetime'] = $row['date_updated'];
    $out['contents'] = base64_encode($contents);
  }
}

