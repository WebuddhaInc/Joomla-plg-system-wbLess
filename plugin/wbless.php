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

  private $_lessParser;
  private $_lessEnvironment;

  /*
   *
   *  Run after system dispatch
   *
   */
  public function __construct(&$subject, $config){
    parent::__construct($subject, $config);
  }

  /*
   *
   *  Dispatch Events
   *
   *
   */
  public function onAfterDispatch(){
    $this->_onEvent( __FUNCTION__ );
  }
  public function onBeforeExecute(){
    $this->_onEvent( __FUNCTION__ );
  }
  public function onAfterExecute(){
    $this->_onEvent( __FUNCTION__ );
  }

  /*
   *
   *  Handle Dispatch Events
   *
   *
   */
  private function _onEvent( $eventName, $eventParams = array() ){
    if( $eventName == $this->params->get('trigger_event', 'onAfterDispatch') ){
      $this->_execute();
    }
  }

  /*
   *
   *  Execute wbLess processing
   *
   *
   */
  private function _execute(){

    $app = JFactory::getApplication();
    if( $app->isAdmin() ){

      // Skip admin requests

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
                $files  = isset($lessrc_json->files) ? $lessrc_json->files : array($lessrc_json);
                $shared = isset($lessrc_json->import) ? $lessrc_json->import : array();
                foreach( $files AS $file ){
                  $dependentFile = $source_path . ($lessrc_file['name'] == '.lessrc' ? '*' : substr($lessrc_file['name'], 0, strlen($lessrc_file['name'])-2));
                  if( isset($file->file) && is_string($file->file) ){
                    $dependentFile = $source_path . $file->file;
                  }
                  $importLookupList = ( isset($file->import) && is_array($file->import) ? $file->import : array() );
                  $importLookupList = array_merge( $importLookupList, $shared );
                  foreach( $importLookupList AS $import ){
                    $target_file = self::normalizePath( $source_path . $import );
                    if( is_readable($target_file) ){
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
              else {
                throw new Exception('Error parsing contents of ' . $source_path . $source_file);
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
                      $less_matches = (
                        isset($lessDependent[$source_path.$source_file])
                          ? $lessDependent[$source_path.$source_file]
                          : (
                            isset($lessDependent[$source_path.'*'])
                            ? $lessDependent[$source_path.'*']
                            : null
                            )
                        );
                      if( $less_matches ){
                        foreach( $less_matches AS $dependencyFile => $dependencyFileTime ){
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
                  $lessProcessed[] = array($source_path.$source_file, $target_path.$target_file);
                  $this->_compileLessFile(
                      array('path' => $source_path, 'file' => $source_file),
                      array('path' => $target_path, 'file' => $target_file)
                      );

                  /*
                   * INC PARSER WRAPPER
                   *
                  if( empty($lessParser) ){
                    require_once 'lessc/lessc.inc.php';
                    $lessCompiler = new lessc();
                    if( $this->params->get('compress', 0) ){
                      $lessCompiler->setFormatter('compressed');
                    }
                  }
                  if( isset($lessCompiler) ){
                    $lessProcessed[] = array($source_path.$source_file, $target_path.$target_file);
                    $lessCompiler->compileFile( $source_path.$source_file, $target_path.$target_file );
                  }
                   */

                  /*
                   * LIB PARSER DIRECTLY
                   *
                    if( empty($lessParser) ){
                      if( !class_exists('Less_Parser') ){
                        require_once 'lessc/lib/Less/Autoloader.php';
                        Less_Autoloader::register();
                      }
                      $lessParser = new Less_Parser(array(
                        'compress' => $this->params->get('compress', 0)
                        ));
                    }
                    if( isset($lessParser) ){
                      $lessProcessed[] = array($source_path.$source_file, $target_path.$target_file);
                      $lessParser->parseFile( $source_path.$source_file, $source_path );
                      file_put_contents( $target_path.$target_file, $lessParser->getCss() );
                    }
                  */

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

  /*
   *
   *  Normalize Path (remove dot notations)
   *
   */
  public static function normalizePath($path){
    $result = preg_replace('/(\/\.\/\|\.\/)/','/',$path);
    // $regex  = '/\/[A-Za-z\s\.\,\-\_\+\=\[\]\{\}\:\;\"\'\<\>]+\/\.\.\//';
    $regex  = '/\/[^\/]+\/\.\.\//';
    do {
      $lastResult = $result;
      if( preg_match($regex,$result) ){
        $result = preg_replace($regex, '/', $result, 1);
      }
    } while( $result != $lastResult );
    return $result;
  }

  /*
   *
   *  Write a compiled LESS file to disk
   *
   */
  private function _compileLessFile( $inFileInfo, $outFileInfo = null ){

    // Input File
      $inFile = null;
      $inPath = null;
      if( isset($inFileInfo['path']) ){
        $inPath = $inFileInfo['path'];
        $inFile = $inFileInfo['file'];
      }
      else {
        $inFile = (string)$inFileInfo;
      }
      $inFullPath = ($inPath?$inPath:'') . $inFile;

    // Output File
      $outFile = null;
      $outPath = null;
      if( isset($outFileInfo['path']) ){
        $outPath = $outFileInfo['path'];
        $outFile = $outFileInfo['file'];
      }
      else if( !is_null($outFileInfo) ) {
        $outFile = (string)$outFileInfo;
      }
      if( is_null($outFile) ){
        $outFullPath = ($outPath?$outPath:'') . preg_replace('/\.less$/','',$inFile) . '.css';
      }
      else {
        $outFullPath = ($outPath?$outPath:'') . $outFile;
      }

    // Verify File
      if (!is_readable($inFullPath)) {
        throw new Exception('failed to find file '.$inFullPath);
      }

    // Load Parser
      if( empty($this->_lessParser) ){
        if( !class_exists('Less_Parser') ){
          require_once 'lessc/lib/Less/Autoloader.php';
          Less_Autoloader::register();
        }
        $this->_lessEnvironment = new Less_Environment();
        $this->_lessParser = new Less_Parser( $this->_lessEnvironment );
      }

    // Parse & Store
      $lessParser = $this->_lessParser;
      if( isset($lessParser) ){
        // Prepare callback for symlink correction
          $importDirs = array();
          if( realpath($inPath) != $inPath ){
            $importDirs[$inPath] = create_function('$fname', 'return array(Less_Environment::normalizePath(\''.$inPath.'\'.$fname), null);');
          }
        // Reset Parser
          $lessParser->Reset(
            array(
              'import_dirs' => $importDirs,
              'compress' => $this->params->get('compress', 0)
            ));
        // Parse & Write Output
          $lessParser->parseFile( $inFullPath );
          file_put_contents( $outFullPath, $lessParser->getCss() );
      }
      else {
        throw new Exception('failed to load parser');
      }

  }

  /*
   *
   *  Scan folder for regex dir / filename match
   *
   */
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

