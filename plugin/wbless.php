<?php

/*
  wbPack - Javascript Package for Joomla
*/

// check that we have access
defined( '_JEXEC' ) or die( 'Restricted access' );

// Load System
jimport( 'joomla.plugin.plugin' );

defined('DS') or define('DS', DIRECTORY_SEPARATOR);

if( !function_exists('inspect') ){
  function inspect(){
    echo '<pre>' . print_r( func_get_args(), true ) . '</pre>';
  }
}

class plgSystemWbLess extends JPlugin {

  function __construct(&$subject, $config){
    parent::__construct($subject, $config);
  }

  function onAfterDispatch(){

    $app = JFactory::getApplication();
    if( $app->isAdmin() ){
    }
    else {

      // Find Watch Paths
        $watch_paths  = explode("\r\n", $this->params->get('watch_paths'));
        $watch_config = array();
        $template = JFactory::getApplication()->getTemplate();
        for($i=0;$i<count($watch_paths);$i++){
          $watch_path = trim(preg_replace('/[\r\n]/','',preg_replace('/\{\$template\}/', $template, $watch_paths[$i])));
          if( strlen($watch_path) ){
            $abs_path  = JPATH_BASE . DIRECTORY_SEPARATOR . $watch_path;
            if( $abs_path != JPATH_BASE . DIRECTORY_SEPARATOR && is_dir($abs_path) ){
              $watch_config[ $watch_path ] = array(
                'abs_path' => $abs_path
                );
            }
          }
        }

      // Examing dependencies
        $lessDependent = array();
        $partialMTimes = array();
        foreach($watch_config AS $watch_path => $watch_path_config){
          $abs_path = $watch_path_config['abs_path'];
          $lessrc_files = self::_find_files( $abs_path, '/\.lessrc$/' );
          if( $lessrc_files ){
            foreach( $lessrc_files AS $lessrc_file ){
              $source_path = $lessrc_file['path'] . DIRECTORY_SEPARATOR;
              $source_file = $lessrc_file['name'];
              $lessrc_json = json_decode( file_get_contents($source_path . $source_file) );
              if( is_object($lessrc_json) ){
                $files = isset($lessrc_json->files) ? $lessrc_json->files : array($lessrc_json);
                foreach( $files AS $file ){
                  $dependentFile = $source_path . ($lessrc_file['name'] == '.lessrc' ? '*' : substr($lessrc_file['name'], 0, strlen($lessrc_file['name'])-2));
                  if( isset($file->file) && is_string($file->file) ){
                    $dependentFile = $source_path . $file->file;
                  }
                  if( isset($file->import) && is_array($file->import) ){
                    foreach( $file->import AS $import ){
                      $target_file = $source_path . $import;
                      if( empty($lessDependent[ $dependentFile ]) ){
                        $lessDependent[ $dependentFile ] = array();
                      }
                      if( empty($partialMTimes[$target_file]) ){
                        $partialMTimes[$target_file] = filemtime($target_file);
                      }
                      if( empty($lessDependent[ $dependentFile ][$target_file]) ){
                        $lessDependent[ $dependentFile ][ $target_file ] = $partialMTimes[$target_file];
                      }
                    }
                  }
                }
              }
            }
          }
        }

      // Look for Less Files & Process
        $lessProcessed = array();
        foreach($watch_config AS $watch_path => $watch_path_config){
          $abs_path = $watch_path_config['abs_path'];
          $less_files = self::_find_files( $abs_path, '/\.less$/' );
          if( $less_files ){
            $target_base = $abs_path;
            if( substr($target_base, strlen($target_base) - 5) == ('less'.DIRECTORY_SEPARATOR) ){
              $target_base = substr($target_base, 0, strlen($target_base) - 5) . 'css' . DIRECTORY_SEPARATOR;
            }
            foreach( $less_files AS $less_file ){
              $source_path = $less_file['path'] . DIRECTORY_SEPARATOR;
              $source_file = $less_file['name'];
              if( substr($source_file,0,1) != '_' ){
                $target_path = $target_base . substr($source_path, strlen($abs_path));
                $target_file = preg_replace('/\.less$/','.css',$less_file['name']);
                if( is_dir($target_path) ){
                  if( is_readable($target_path.$target_file) ){
                    $source_filemtime = filemtime($source_path.$source_file);
                    $target_filemtime = filemtime($target_path.$target_file);
                    if( $source_filemtime < $target_filemtime ){
                      $less_is_more = true;
                      if( isset($lessDependent[$source_path.$source_file]) ){
                        foreach( $lessDependent[$source_path.$source_file] AS $dependencyFile => $dependencyFileTime ){
                          if( $target_filemtime < $dependencyFileTime ){
                            $less_is_more = false;
                            break;
                          }
                        }
                      }
                      if( $less_is_more ){
                        continue;
                      }
                    }
                  }
                  if( empty($less) ){
                    require_once 'lessc/lessc.inc.php';
                    $less = new lessc;
                  }
                  if( isset($less) ){
                    $lessProcessed[] = array($source_path.$source_file, $target_path.$target_file);
                    $less->compileFile( $source_path.$source_file, $target_path.$target_file );
                  }
                }
              }
            }
          }
        }

      // Debug
        if( !empty($debug) ){
          inspect( $watch_config, $lessDependent, $lessProcessed );
        }

    }

  }

  private function &_find_files($path,$regex='/.*/',$recurse=true,$folders=false){
    $path = preg_replace('/\/$/','',$path);
    $fileList = Array();
    if( is_null($regex) )
      $regex = '/.*/';
    if( is_dir($path) ){
      $dir = opendir($path);
      while (false !== ($file = readdir($dir))){
        $filePath = $path.'/'.$file;
        if(is_dir($filePath)){
          if( $folders && !preg_match('/^\.+$/',$file) && preg_match($regex,$file) )
            $fileList[] = Array(
              'name'    => $file,
              'path'    => $path,
              'size'    => null,
              'type'    => null,
              'is_dir'  => true
              );
          if( $recurse && !in_array($file,Array('.','..')) )
            $fileList = array_merge($fileList,self::_find_files($filePath,$regex));
        } elseif(preg_match($regex,$file)) {
          $fileList[] = Array(
            'name'    => $file,
            'path'    => $path,
            'size'    => filesize($filePath),
            'type'    => strtolower(preg_replace('/^.*\.(\w+)$/','$1',$file)),
            'is_dir'  => false
            );
        }
      }
      closedir($dir);
    }
    return $fileList;
  }

}

