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
        'chunk_sum' => array(),
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

  // move uploaded file
  move_uploaded_file($_FILES['file']['tmp_name'], $chunkfile_path);
  file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] uploaded '.$chunkfile_path."\n", FILE_APPEND);

  // MD5 checksum
  $chunk_md5 = md5_file($chunkfile_path);
  if ($chunk_md5 != $params['chunk_sum'])
  {
    unlink($chunkfile_path);
    file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] '.$chunkfile_path." MD5 checksum mismatched!\n", FILE_APPEND);
    return new PwgError(500, "MD5 checksum chunk file mismatched");
  }

  // are all chunks uploaded?
  $chunk_ids_uploaded = array();
  for ($i = 1; $i <= $params['chunks']; $i++)
  {
    $chunkfile = sprintf($chunkfile_path_pattern, $i, $params['chunks']);
    if ( file_exists($chunkfile) && ($fp = fopen($chunkfile, "rb"))!==false )
    {
      $chunk_ids_uploaded[] = $i;
      fclose($fp);
    }
  }

  // file_put_contents('/tmp/uploadAsync.log', 'chunks uploaded = '.implode(',', $chunk_ids_uploaded)."\n", FILE_APPEND);
  // file_put_contents('/tmp/uploadAsync.log', 'nb chunks  = '.$params['chunks']."\n", FILE_APPEND);

  if ($params['chunks'] != count($chunk_ids_uploaded))
  {
    // all chunks are not yet available
    return array('message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded));
  }
  
  // all chunks available
  file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] '.$params['original_sum'].' '.$params['chunks']." chunks available\n", FILE_APPEND);
  $output_filepath = $output_filepath_prefix.'.merged';
  
  // chunks already being merged?
  if ( file_exists($output_filepath) && ($fp = fopen($output_filepath, "rb"))!==false )
  {
    // merge file already exists
    fclose($fp);
    file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] '.$output_filepath." already exists\n", FILE_APPEND);
    return array('message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded));
  }
  
  // create merged and open it for writing only
  $fp = fopen($output_filepath, "wb");
  if ( !$fp )
  {
    // unable to create file and open it for writing only
    file_put_contents('/tmp/uploadAsync.log', '['.date('c').']'.$chunkfile_path." unable to create merged\n", FILE_APPEND);
    return new PwgError(500, 'error while creating merged '.$chunkfile_path);
  }
  // acquire an exclusive lock and keep it until merge completes
  // this postpones another uploadAsync task running in another thread
  if (!flock($fp, LOCK_EX)) {
    // unable to obtain lock
    fclose($fp);
    file_put_contents('/tmp/uploadAsync.log', '['.date('c').']'.$chunkfile_path." unable to obtain lock\n", FILE_APPEND);
    return new PwgError(500, 'error while locking merged '.$chunkfile_path);
  }

  // loop over all chunks
  foreach ($chunk_ids_uploaded as $chunk_id)
  {
    $chunkfile_path = sprintf($chunkfile_path_pattern, $chunk_id, $params['chunks']);

    // chunk deleted by preceding merge?
    if (!file_exists($chunkfile_path))
    {
      // cancel merge
      file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] '.$chunkfile_path." already merged\n", FILE_APPEND);
      flock($fp, LOCK_UN);
      fclose($fp);
      return array('message' => 'chunks uploaded = '.implode(',', $chunk_ids_uploaded));
    }

    if (!fwrite($fp, file_get_contents($chunkfile_path)))
    {
      // could not append chunk
      file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] error merging chunk '.$chunkfile_path."\n", FILE_APPEND);
      flock($fp, LOCK_UN);
      fclose($fp);
      // delete merge file without returning an error
      @unlink($output_filepath);
      return new PwgError(500, 'error while merging chunk '.$chunk_id);
    }

    // delete chunk and clear cache
    unlink($chunkfile_path);
  }

  // flush output before releasing lock
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  file_put_contents('/tmp/uploadAsync.log', '['.date('c')."] ".$output_filepath." saved\n", FILE_APPEND);
  
  // MD5 checksum
  $merged_md5 = md5_file($output_filepath);

  if ($merged_md5 != $params['original_sum'])
  {
    unlink($output_filepath);
    file_put_contents('/tmp/uploadAsync.log', '['.date('c').'] '.$output_filepath." MD5 checksum mismatched!\n", FILE_APPEND);
    return new PwgError(500, "MD5 checksum merged file mismatched");
  }
  file_put_contents('/tmp/uploadAsync.log', '['.date('c')."] ".$output_filepath." MD5 checksum Ok\n", FILE_APPEND);

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

  // delete chunks older than a week
  $now = time();
  foreach (glob($conf['upload_dir'].'/buffer/'."*.chunk") as $file) {
    if (is_file($file)) {
      if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
        file_put_contents('/tmp/uploadAsync.log', 'delete '.$file."\n", FILE_APPEND);
        unlink($file);
      } else {
        file_put_contents('/tmp/uploadAsync.log', 'keep '.$file."\n", FILE_APPEND);
      }
    }
  }

  // delete merged older than a week
  foreach (glob($conf['upload_dir'].'/buffer/'."*.merged") as $file) {
    if (is_file($file)) {
      if ($now - filemtime($file) >= 60 * 60 * 24 * 7) { // 7 days
        file_put_contents('/tmp/uploadAsync.log', 'delete '.$file."\n", FILE_APPEND);
        unlink($file);
      } else {
        file_put_contents('/tmp/uploadAsync.log', 'keep '.$file."\n", FILE_APPEND);
      }
    }
  }

  return $service->invoke('pwg.images.getInfo', array('image_id' => $image_id));
}

function file_exists_safe($file) {
    // tries to create and open for writing only
    if (!$fd = fopen($file, 'xb')) {
        return true;  // the file already exists
    }
    fclose($fd);  // the file is now created, we don't need the file handler
    return false;
}
