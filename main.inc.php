<?php 
/*
Plugin Name: uploadAsync
Version: auto
Description: add method pwg.images.uploadAsync (to be integrated into Piwigo core 2.11)
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=
Author: plg
Author URI: https://piwigo.org
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

global $prefixeTable;

// +-----------------------------------------------------------------------+
// | Define plugin constants                                               |
// +-----------------------------------------------------------------------+

defined('UPLOADASYNC_ID') or define('UPLOADASYNC_ID', basename(dirname(__FILE__)));
define('UPLOADASYNC_PATH' , PHPWG_PLUGINS_PATH.basename(dirname(__FILE__)).'/');
define('UPLOADASYNC_VERSION', 'auto');

add_event_handler('ws_add_methods', 'uploadasync_add_methods');
function uploadasync_add_methods($arr)
{
  global $conf;

  $service = &$arr[0];

  $service->addMethod(
    'pwg.images.uploadAsync',
    'ws_images_uploadAsync',
    array(
        'username' => array(),
        'password' => array('default'=>null),
        'chunk' => array('type'=>WS_TYPE_INT|WS_TYPE_POSITIVE),
        'chunks' => array('type'=>WS_TYPE_INT|WS_TYPE_POSITIVE),
        'original_sum' => array(),
        'category' => array('default'=>null, 'flags'=>WS_PARAM_FORCE_ARRAY, 'type'=>WS_TYPE_ID),
        'filename' => array(),
        'name' => array('default'=>null),
        'author' => array('default'=>null),
        'comment' => array('default'=>null),
        'date_creation' => array('default'=>null),
        'level' => array('default'=>0, 'maxValue'=>max($conf['available_permission_levels']), 'type'=>WS_TYPE_INT|WS_TYPE_POSITIVE),
        'tag_ids' => array('default'=>null, 'info'=>'Comma separated ids'),
        'image_id' => array('default'=>null, 'type'=>WS_TYPE_ID),
    ),
    'Upload photo by chunks in a random order.
<br>Use the <b>$_FILES[file]</b> field for uploading file.
<br>Start with chunk 0 (zero).
<br>Set the form encoding to "form-data".
<br>You can update an existing photo if you define an existing image_id.
<br>Requires <b>admin</b> credentials.'
    );
}

function ws_images_uploadAsync($params, &$service)
{
  global $conf, $user;

  // file_put_contents('/tmp/uploadAsync.log', 'user_id/user_status  = '.$user['id'].'/'.$user['status']."\n", FILE_APPEND);

  // additional check for some parameters
  if (!preg_match('/^[a-fA-F0-9]{32}$/', $params['original_sum']))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid original_sum');
  }

  if (!try_log_user($params['username'], $params['password'], false))
  {
    return new PwgError(999, 'Invalid username/password');
  }

  // build $user
  // include(PHPWG_ROOT_PATH.'include/user.inc.php');
  $user = build_user($user['id'], false);

  // file_put_contents('/tmp/uploadAsync.log', 'user_id/user_status  = '.$user['id'].'/'.$user['status']."\n", FILE_APPEND);

  if (!is_admin())
  {
    return new PwgError(401, 'Admin status is required.');
  }

  if ($params['image_id'] > 0)
  {
    $query='
SELECT COUNT(*)
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $params['image_id'] .'
;';
    list($count) = pwg_db_fetch_row(pwg_query($query));
    if ($count == 0)
    {
      return new PwgError(404, __FUNCTION__.' : image_id not found');
    }
  }

  // handle upload error as in ws_images_addSimple
  // if (isset($_FILES['image']['error']) && $_FILES['image']['error'] != 0)

  $output_filepath_prefix = $conf['upload_dir'].'/buffer/'.$params['original_sum'].'-u'.$user['id'];
  $chunkfile_path_pattern = $output_filepath_prefix.'-%03uof%03u.chunk';

  $chunkfile_path = sprintf($chunkfile_path_pattern, $params['chunk']+1, $params['chunks']);

  // create the upload directory tree if not exists
  if (!mkgetdir(dirname($chunkfile_path), MKGETDIR_DEFAULT&~MKGETDIR_DIE_ON_ERROR))
  {
    return new PwgError(500, 'error during buffer directory creation');
  }
  secure_directory(dirname($chunkfile_path));

  move_uploaded_file($_FILES['file']['tmp_name'], $chunkfile_path);

  // are all chunks uploaded?
  $chunk_ids_uploaded = array();
  for ($i = 1; $i <= $params['chunks']; $i++)
  {
    if (file_exists(sprintf($chunkfile_path_pattern, $i, $params['chunks'])))
    {
      $chunk_ids_uploaded[] = $i;
    }
  }

  // file_put_contents('/tmp/uploadAsync.log', 'chunks uploaded = '.implode(',', $chunk_ids_uploaded)."\n", FILE_APPEND);
  // file_put_contents('/tmp/uploadAsync.log', 'nb chunks  = '.$params['chunks']."\n", FILE_APPEND);

  if ($params['chunks'] == count($chunk_ids_uploaded))
  {
    global $prefixeTable;

    $token_tablename = $prefixeTable.'upload_tokens';

    $query = '
CREATE TABLE IF NOT EXISTS `'.$token_tablename.'` (
  `file_md5` CHAR(32) NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `execution_id` char(10) NOT NULL,
  `created_on` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (file_md5, user_id)
);';
    pwg_query($query);

    $execution_id = generate_key(10);

    single_insert(
      $token_tablename,
      array(
        'file_md5' => $params['original_sum'],
        'user_id' => $user['id'],
        'execution_id' => $execution_id,
      ),
      array('ignore'=>true)
    );

    // the 123456=123456 (random) in the SQL query makes sure it doesn't use the MySQL cache, that's a trick
    $rand_int = rand(100000, 999999);

    $query = '
SELECT `execution_id`
  FROM `'.$token_tablename.'`
  WHERE `file_md5` = \''.$params['original_sum'].'\'
    AND `user_id` = '.$user['id'].'
    AND '.$rand_int.'='.$rand_int.'
;';
    file_put_contents('/tmp/uploadAsync.log', 'query  = '.$query."\n", FILE_APPEND);

    $tokens = query2array($query, null, 'execution_id');

    if ($tokens[0] == $execution_id)
    {
      $query = '
DELETE
  FROM `'.$token_tablename.'`
  WHERE `file_md5` = \''.$params['original_sum'].'\'
    AND `user_id` = '.$user['id'].'
;';
      pwg_query($query);

      $output_filepath = $output_filepath_prefix.'.merged';

      // start with a clean output merge file
      file_put_contents($output_filepath, '');

      foreach ($chunk_ids_uploaded as $chunk_id)
      {
        $chunkfile_path = sprintf($chunkfile_path_pattern, $chunk_id, $params['chunks']);

        if (!file_put_contents($output_filepath, file_get_contents($chunkfile_path), FILE_APPEND))
        {
          return new PwgError(500, 'error while merging chunk '.$chunk_id);
        }

        unlink($chunkfile_path);
      }

      $merged_md5 = md5_file($output_filepath);

      if ($merged_md5 != $params['original_sum'])
      {
        unlink($output_filepath);
        return new PwgError(500, 'provided original_sum '.$params['original_sum'].' does not match with merged file sum '.$merged_md5);
      }

      include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

      $image_id = add_uploaded_file(
        $output_filepath,
        $params['filename'],
        $params['category'],
        $params['level'],
        $params['image_id'],
        $params['original_sum']
      );

      // file_put_contents('/tmp/uploadAsync.log', 'image_id after add_uploaded_file = '.$image_id."\n", FILE_APPEND);

      // and now, let's create tag associations
      if (isset($params['tag_ids']) and !empty($params['tag_ids']))
      {
        set_tags(
          explode(',', $params['tag_ids']),
          $image_id
        );
      }

      // time to set other infos
      $info_columns = array(
        'name',
        'author',
        'comment',
        'date_creation',
      );

      $update = array();
      foreach ($info_columns as $key)
      {
        if (isset($params[$key]))
        {
          $update[$key] = $params[$key];
        }
      }
  
      if (count(array_keys($update)) > 0)
      {
        single_update(
          IMAGES_TABLE,
          $update,
          array('id' => $image_id)
        );
      }

      // final step, reset user cache
      invalidate_user_cache();

      // trick to bypass get_sql_condition_FandF
      if (!empty($params['level']) and $params['level'] > $user['level'])
      {
        // this will not persist
        $user['level'] = $params['level'];
      }

      return $service->invoke('pwg.images.getInfo', array('image_id' => $image_id));
    }
  }

  return array('message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded));
}
